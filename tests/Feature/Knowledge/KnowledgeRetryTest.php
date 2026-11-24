<?php

namespace Tests\Feature\Knowledge;

use App\Actions\Knowledge\DispatchKnowledgeIngestion;
use App\Actions\Knowledge\FailKnowledgeSource;
use App\Contracts\EmbeddingClient;
use App\Contracts\VectorStore;
use App\Enums\KnowledgeFailureReason;
use App\Enums\KnowledgeSourceStatus;
use App\Jobs\Knowledge\ChunkKnowledgeText;
use App\Jobs\Knowledge\EmbedKnowledgeChunks;
use App\Jobs\Knowledge\ExtractKnowledgeText;
use App\Jobs\Knowledge\PurgeKnowledgeIndexJob;
use App\Models\Chatbot;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Knowledge\FakeEmbeddingClient;
use Tests\Support\Knowledge\FakeVectorStore;
use Tests\TestCase;

class KnowledgeRetryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Chatbot $chatbot;

    private FakeEmbeddingClient $embeddings;

    private FakeVectorStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->embeddings = new FakeEmbeddingClient;
        $this->store = new FakeVectorStore;
        $this->app->instance(EmbeddingClient::class, $this->embeddings);
        $this->app->instance(VectorStore::class, $this->store);

        $this->owner = User::factory()->create();
        $this->chatbot = Chatbot::factory()->for($this->owner)->create();
    }

    private function failedSource(KnowledgeFailureReason $reason = KnowledgeFailureReason::EmbeddingUnavailable, bool $withFile = true): KnowledgeSource
    {
        $path = "knowledge/{$this->owner->id}/{$this->chatbot->ulid}/file.txt";

        if ($withFile) {
            Storage::disk('local')->put($path, "Primeiro parágrafo do documento.\n\nSegundo parágrafo do documento.");
        }

        return KnowledgeSource::factory()->for($this->chatbot)->create([
            'disk_path' => $path,
            'mime' => 'text/plain',
            'original_name' => 'file.txt',
            'status' => KnowledgeSourceStatus::Failed,
            'failure_reason' => $reason,
            'chunks_count' => 3,
        ]);
    }

    private function retryUrl(KnowledgeSource $source, ?Chatbot $chatbot = null): string
    {
        return route('chatbots.sources.retry', [$chatbot ?? $this->chatbot, $source]);
    }

    public function test_owner_retry_resets_the_source_and_dispatches_a_fresh_chain(): void
    {
        Bus::fake();
        $source = $this->failedSource();

        $this->actingAs($this->owner)->postJson($this->retryUrl($source))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.failure_reason', null);

        $source->refresh();
        $this->assertSame(KnowledgeSourceStatus::Pending, $source->status);
        $this->assertNull($source->failure_reason);

        Bus::assertChained([
            PurgeKnowledgeIndexJob::class,
            ExtractKnowledgeText::class,
            ChunkKnowledgeText::class,
            EmbedKnowledgeChunks::class,
        ]);
    }

    public function test_retry_is_refused_for_another_users_source(): void
    {
        Bus::fake();
        $source = $this->failedSource();

        $this->actingAs(User::factory()->create())->postJson($this->retryUrl($source))->assertNotFound();

        $this->assertSame(KnowledgeSourceStatus::Failed, $source->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_retry_through_another_chatbot_of_the_same_user_is_a_404(): void
    {
        Bus::fake();
        $source = $this->failedSource();
        $other = Chatbot::factory()->for($this->owner)->create();

        $this->actingAs($this->owner)->postJson($this->retryUrl($source, $other))->assertNotFound();

        Bus::assertNothingDispatched();
    }

    public function test_guests_cannot_retry(): void
    {
        Bus::fake();
        $source = $this->failedSource();

        $this->postJson($this->retryUrl($source))->assertUnauthorized();
    }

    public function test_retry_is_blocked_unless_the_source_failed(): void
    {
        Bus::fake();

        foreach ([KnowledgeSourceStatus::Pending, KnowledgeSourceStatus::Extracting, KnowledgeSourceStatus::Embedding, KnowledgeSourceStatus::Indexed, KnowledgeSourceStatus::Deleting] as $status) {
            $source = $this->failedSource();
            $source->update(['status' => $status, 'failure_reason' => null]);

            $this->actingAs($this->owner)->postJson($this->retryUrl($source))->assertStatus(422);

            $this->assertSame($status, $source->fresh()->status);
        }

        Bus::assertNothingDispatched();
    }

    public function test_retry_is_blocked_when_reprocessing_cannot_change_the_outcome(): void
    {
        Bus::fake();
        $source = $this->failedSource(KnowledgeFailureReason::EmptyDocument);

        $this->actingAs($this->owner)->postJson($this->retryUrl($source))->assertStatus(422);

        $this->assertSame(KnowledgeSourceStatus::Failed, $source->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_retry_explains_when_the_original_file_is_gone(): void
    {
        Bus::fake();
        $source = $this->failedSource(withFile: false);

        $this->actingAs($this->owner)->postJson($this->retryUrl($source))
            ->assertStatus(422)
            ->assertJsonPath('message', 'O arquivo original não está mais disponível. Exclua o documento e envie o arquivo novamente.');

        $this->assertSame(KnowledgeSourceStatus::Failed, $source->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_second_retry_does_not_dispatch_twice(): void
    {
        Bus::fake();
        $source = $this->failedSource();

        $this->actingAs($this->owner)->postJson($this->retryUrl($source))->assertStatus(202);
        $this->actingAs($this->owner)->postJson($this->retryUrl($source))->assertStatus(422);

        Bus::assertDispatchedTimes(PurgeKnowledgeIndexJob::class, 1);
    }

    public function test_retry_is_throttled(): void
    {
        Bus::fake();
        config(['knowledge.retries_per_minute_user' => 2]);
        $source = $this->failedSource();

        // The first call is accepted, the next ones are refused by the state check; all of them count.
        $this->actingAs($this->owner)->postJson($this->retryUrl($source))->assertStatus(202);
        $this->actingAs($this->owner)->postJson($this->retryUrl($source))->assertStatus(422);
        $this->actingAs($this->owner)->postJson($this->retryUrl($source))->assertStatus(429);
    }

    public function test_purge_job_clears_chunks_and_vectors_but_keeps_the_original_file(): void
    {
        $source = $this->failedSource();
        Storage::disk('local')->put($source->extractedTextPath(), 'texto');
        KnowledgeChunk::factory()->for($source, 'source')->count(3)->create(['embedded_at' => now()]);
        $this->store->upsert([
            ['id' => 'a', 'vector' => [0.1], 'payload' => ['chatbot_id' => $this->chatbot->ulid, 'source_id' => $source->ulid, 'chunk_id' => 'a']],
            ['id' => 'b', 'vector' => [0.1], 'payload' => ['chatbot_id' => $this->chatbot->ulid, 'source_id' => 'outro', 'chunk_id' => 'b']],
        ]);

        PurgeKnowledgeIndexJob::dispatchSync($source);

        $this->assertSame([[$this->chatbot->ulid, $source->ulid]], $this->store->deletedSources);
        $this->assertSame(['b'], array_keys($this->store->points));
        $this->assertSame(0, KnowledgeChunk::query()->count());
        $this->assertSame(0, $source->fresh()->chunks_count);
        Storage::disk('local')->assertExists($source->disk_path);
        Storage::disk('local')->assertMissing($source->extractedTextPath());
    }

    public function test_full_recovery_after_the_embedding_service_comes_back_without_duplicates(): void
    {
        config(['queue.default' => 'sync']);
        $source = $this->failedSource();
        $this->embeddings->down = true;

        // First run: extraction and chunking work, embedding is down.
        $source->update(['status' => KnowledgeSourceStatus::Pending, 'failure_reason' => null, 'chunks_count' => 0]);
        try {
            app(DispatchKnowledgeIngestion::class)($source);
        } catch (\Throwable) {
            // Sync queue rethrows after the failure callback ran.
        }

        $source->refresh();
        $this->assertSame(KnowledgeSourceStatus::Failed, $source->status);
        $this->assertSame(KnowledgeFailureReason::EmbeddingUnavailable, $source->failure_reason);
        $chunksBefore = KnowledgeChunk::query()->count();
        $this->assertGreaterThan(0, $chunksBefore);

        // Embeddings are back: the owner retries.
        $this->embeddings->down = false;
        $this->actingAs($this->owner)->postJson($this->retryUrl($source))->assertStatus(202);

        $source->refresh();
        $this->assertSame(KnowledgeSourceStatus::Indexed, $source->status);
        $this->assertNull($source->failure_reason);
        $this->assertSame($chunksBefore, KnowledgeChunk::query()->count());
        $this->assertCount($chunksBefore, $this->store->points);
        $this->assertSame($chunksBefore, $source->chunks_count);
    }

    public function test_fail_action_stores_only_the_code_and_logs_the_full_exception(): void
    {
        Log::spy();
        $source = $this->failedSource();
        $source->update(['status' => KnowledgeSourceStatus::Embedding, 'failure_reason' => null]);

        $this->embeddings->down = true;
        try {
            $this->embeddings->embedPassages(['x']);
        } catch (ConnectionException $e) {
            app(FailKnowledgeSource::class)($source->id, $e);
        }

        $source->refresh();
        $this->assertSame(KnowledgeSourceStatus::Failed, $source->status);
        $this->assertSame(KnowledgeFailureReason::EmbeddingUnavailable, $source->failure_reason);
        $this->assertNull($source->error);
        Log::shouldHaveReceived('error')->withArgs(
            fn ($message, $context) => $message === 'Knowledge ingestion failed'
                && $context['source_id'] === $source->id
                && $context['exception'] instanceof ConnectionException,
        )->once();
    }

    public function test_panel_payload_has_a_fixed_message_and_no_internal_details(): void
    {
        $source = $this->failedSource();

        $response = $this->actingAs($this->owner)->getJson(route('chatbots.sources.index', $this->chatbot))
            ->assertOk()
            ->assertJsonPath('data.0.failure_reason', 'embedding_unavailable')
            ->assertJsonPath('data.0.error', KnowledgeFailureReason::EmbeddingUnavailable->message())
            ->assertJsonPath('data.0.can_retry', true);

        $body = $response->getContent();
        $this->assertStringNotContainsString('webapp_embeddings', $body);
        $this->assertStringNotContainsString('cURL', $body);
        $this->assertStringNotContainsString($source->disk_path, $body);
    }

    public function test_legacy_failed_rows_without_a_reason_show_the_generic_message_and_can_retry(): void
    {
        $source = $this->failedSource();
        $source->update(['failure_reason' => null, 'error' => 'Falha antiga com http://tei.internal/x']);

        $response = $this->actingAs($this->owner)->getJson(route('chatbots.sources.index', $this->chatbot))
            ->assertJsonPath('data.0.failure_reason', 'unknown')
            ->assertJsonPath('data.0.can_retry', true);

        $this->assertStringNotContainsString('tei.internal', $response->getContent());
    }

    public function test_panel_view_offers_the_retry_button(): void
    {
        $this->failedSource();

        $this->actingAs($this->owner)->get(route('chatbots.show', $this->chatbot))
            ->assertOk()
            ->assertSee('Tentar novamente')
            ->assertSee(KnowledgeFailureReason::EmbeddingUnavailable->message(), false);
    }

    public function test_embedding_job_retries_transient_failures_before_giving_up(): void
    {
        $job = new EmbedKnowledgeChunks($this->failedSource());

        $this->assertGreaterThanOrEqual(5, $job->tries);
        $this->assertGreaterThanOrEqual($job->tries - 1, count($job->backoff()));
        $this->assertGreaterThanOrEqual(120, array_sum($job->backoff()));
    }

    public function test_failure_is_only_recorded_when_the_chain_gives_up(): void
    {
        Bus::fake();
        $source = $this->failedSource();
        $source->update(['status' => KnowledgeSourceStatus::Pending, 'failure_reason' => null]);

        app(DispatchKnowledgeIngestion::class)($source);

        // Nothing marks the source failed at dispatch time: the catch callback only runs when a job exhausts its tries.
        $this->assertSame(KnowledgeSourceStatus::Pending, $source->fresh()->status);
        Bus::assertChained([ExtractKnowledgeText::class, ChunkKnowledgeText::class, EmbedKnowledgeChunks::class]);
    }
}
