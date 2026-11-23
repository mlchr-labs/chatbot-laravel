<?php

namespace Tests\Feature\Knowledge;

use App\Actions\Knowledge\DispatchKnowledgeIngestion;
use App\Actions\Knowledge\FailKnowledgeSource;
use App\Enums\KnowledgeSourceStatus;
use App\Jobs\Knowledge\DeleteKnowledgeSource;
use App\Jobs\Knowledge\EmbedKnowledgeChunks;
use App\Models\Chatbot;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Runs the whole chain on the sync queue with every HTTP call faked.
 */
class KnowledgePipelineTest extends TestCase
{
    use RefreshDatabase;

    private Chatbot $chatbot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.embeddings.dimensions' => 4,
            'services.embeddings.url' => 'http://tei.test',
            'services.qdrant.url' => 'http://qdrant.test:6333',
            'services.qdrant.api_key' => 'secret-key',
            'services.qdrant.collection' => 'knowledge',
        ]);

        Storage::fake('local');
        $this->chatbot = Chatbot::factory()->create();
    }

    private function fakeBackends(int $embedStatus = 200): void
    {
        Http::fake(function (Request $request) use ($embedStatus) {
            if (str_ends_with($request->url(), '/embed')) {
                if ($embedStatus !== 200) {
                    return Http::response('boom internal http://tei.test details', $embedStatus);
                }

                return Http::response(array_map(fn () => [0.1, 0.2, 0.3, 0.4], $request['inputs']));
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/collections/knowledge')) {
                return Http::response(['status' => 'error'], 404);
            }

            return Http::response(['result' => true, 'status' => 'ok']);
        });
    }

    private function sourceWithText(string $text, string $ext = 'txt'): KnowledgeSource
    {
        $path = "knowledge/{$this->chatbot->user_id}/{$this->chatbot->ulid}/file.{$ext}";
        Storage::disk('local')->put($path, $text);

        return KnowledgeSource::factory()->for($this->chatbot)->create([
            'disk_path' => $path,
            'mime' => 'text/plain',
            'original_name' => 'file.'.$ext,
        ]);
    }

    private function longText(int $paragraphs): string
    {
        $parts = [];
        for ($i = 1; $i <= $paragraphs; $i++) {
            $parts[] = "Parágrafo {$i}. ".str_repeat("Conteúdo de teste número {$i} com acentuação. ", 15);
        }

        return implode("\n\n", $parts);
    }

    public function test_chain_extracts_chunks_embeds_in_batches_and_indexes(): void
    {
        $this->fakeBackends();
        $source = $this->sourceWithText($this->longText(45));

        app(DispatchKnowledgeIngestion::class)($source);

        $source->refresh();
        $this->assertSame(KnowledgeSourceStatus::Indexed, $source->status);
        $this->assertNull($source->error);

        $chunks = KnowledgeChunk::query()->where('knowledge_source_id', $source->id)->orderBy('position')->get();
        $this->assertGreaterThan(32, $chunks->count());
        $this->assertSame($chunks->count(), $source->chunks_count);
        $this->assertSame($this->chatbot->id, $chunks->first()->chatbot_id);
        $this->assertNull($chunks->whereNull('embedded_at')->first());

        // Batched: 32 per request, never one request per chunk.
        $embedRequests = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/embed'))->values();
        $this->assertCount((int) ceil($chunks->count() / 32), $embedRequests);
        [$firstRequest] = $embedRequests[0];
        $this->assertCount(32, $firstRequest['inputs']);
        foreach ($embedRequests as [$request]) {
            foreach ($request['inputs'] as $input) {
                $this->assertStringStartsWith('passage: ', $input);
            }
        }

        // Upserts carry the tenant payload, with the api key header.
        $upserts = Http::recorded(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/points'))->values();
        $this->assertCount(count($embedRequests), $upserts);
        [$upsert] = $upserts[0];
        $this->assertSame('secret-key', $upsert->header('api-key')[0]);
        $point = $upsert['points'][0];
        $this->assertSame($chunks->first()->id, $point['id']);
        $this->assertSame([0.1, 0.2, 0.3, 0.4], $point['vector']);
        $this->assertSame([
            'chatbot_id' => $this->chatbot->ulid,
            'source_id' => $source->ulid,
            'chunk_id' => $chunks->first()->id,
        ], $point['payload']);

        // Collection created once, not per chunk or batch.
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'PUT' && preg_match('#/collections/knowledge$#', $r->url())));

        // The intermediate text file is gone.
        Storage::disk('local')->assertMissing($source->extractedTextPath());
    }

    public function test_html_source_is_cleaned_before_chunking(): void
    {
        $this->fakeBackends();
        $source = $this->sourceWithText('<html><script>evil()</script><body><p>Texto &amp; limpo</p></body></html>', 'html');

        app(DispatchKnowledgeIngestion::class)($source);

        $this->assertSame('Texto & limpo', KnowledgeChunk::query()->where('knowledge_source_id', $source->id)->value('content'));
        $this->assertSame(KnowledgeSourceStatus::Indexed, $source->fresh()->status);
    }

    public function test_embed_step_only_processes_chunks_not_yet_embedded(): void
    {
        $this->fakeBackends();
        $source = $this->sourceWithText($this->longText(3));
        $source->update(['status' => KnowledgeSourceStatus::Embedding]);
        $done = KnowledgeChunk::factory()->for($source, 'source')->create(['position' => 0, 'embedded_at' => now()]);
        $todo = KnowledgeChunk::factory()->for($source, 'source')->create(['position' => 1, 'content' => 'pendente']);

        EmbedKnowledgeChunks::dispatchSync($source);

        $embed = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/embed'))->values();
        $this->assertCount(1, $embed);
        $this->assertSame(['passage: pendente'], $embed[0][0]['inputs']);
        $this->assertNotNull($todo->fresh()->embedded_at);
        $this->assertSame(KnowledgeSourceStatus::Indexed, $source->fresh()->status);
    }

    public function test_embedding_failure_marks_the_source_failed_with_a_safe_message(): void
    {
        $this->fakeBackends(embedStatus: 500);
        $source = $this->sourceWithText($this->longText(2));

        try {
            app(DispatchKnowledgeIngestion::class)($source);
            $this->fail('The chain should have thrown on the sync queue.');
        } catch (\Throwable) {
            // Expected: sync queue rethrows after running the catch callbacks.
        }

        $source->refresh();
        $this->assertSame(KnowledgeSourceStatus::Failed, $source->status);
        $this->assertSame(FailKnowledgeSource::GENERIC_MESSAGE, $source->error);
        $this->assertStringNotContainsString('tei.test', $source->error);
    }

    public function test_document_without_text_fails_with_an_actionable_message(): void
    {
        $this->fakeBackends();
        $source = $this->sourceWithText("   \n\n  ");

        try {
            app(DispatchKnowledgeIngestion::class)($source);
        } catch (\Throwable) {
        }

        $source->refresh();
        $this->assertSame(KnowledgeSourceStatus::Failed, $source->status);
        $this->assertStringContainsString('Nenhum texto', $source->error);
        $this->assertSame(0, KnowledgeChunk::query()->count());
        Http::assertNothingSent();
    }

    public function test_delete_job_removes_vectors_file_and_rows(): void
    {
        $this->fakeBackends();
        $source = $this->sourceWithText('conteúdo');
        KnowledgeChunk::factory()->for($source, 'source')->create();
        $source->update(['status' => KnowledgeSourceStatus::Deleting]);

        DeleteKnowledgeSource::dispatchSync($source);

        Http::assertSent(function (Request $r) use ($source) {
            return $r->method() === 'POST'
                && str_ends_with($r->url(), '/collections/knowledge/points/delete?wait=true')
                && $r['filter']['must'] === [
                    ['key' => 'chatbot_id', 'match' => ['value' => $this->chatbot->ulid]],
                    ['key' => 'source_id', 'match' => ['value' => $source->ulid]],
                ];
        });
        Storage::disk('local')->assertMissing($source->disk_path);
        $this->assertDatabaseMissing('knowledge_sources', ['id' => $source->id]);
        $this->assertDatabaseCount('knowledge_chunks', 0);
    }

    public function test_failed_delete_brings_the_source_back_to_the_panel(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $source = $this->sourceWithText('conteúdo');
        $source->update(['status' => KnowledgeSourceStatus::Deleting]);

        $job = new DeleteKnowledgeSource($source);
        $job->failed(new \RuntimeException('qdrant down'));

        $this->assertSame(KnowledgeSourceStatus::Failed, $source->fresh()->status);
        $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id]);
    }

    public function test_jobs_skip_a_source_that_is_being_deleted(): void
    {
        $this->fakeBackends();
        $source = $this->sourceWithText('conteúdo');
        $source->update(['status' => KnowledgeSourceStatus::Deleting]);

        app(DispatchKnowledgeIngestion::class)($source);

        $this->assertSame(KnowledgeSourceStatus::Deleting, $source->fresh()->status);
        $this->assertDatabaseCount('knowledge_chunks', 0);
        Http::assertNothingSent();
    }

    public function test_vectors_are_removed_when_the_source_is_deleted_while_a_batch_is_in_flight(): void
    {
        $source = $this->sourceWithText('conteúdo');
        $source->update(['status' => KnowledgeSourceStatus::Embedding]);
        KnowledgeChunk::factory()->for($source, 'source')->create(['position' => 0]);
        KnowledgeChunk::factory()->for($source, 'source')->create(['position' => 1]);
        config(['knowledge.embed_batch_size' => 1]);

        Http::fake(function (Request $request) use ($source) {
            if (str_ends_with($request->url(), '/embed')) {
                return Http::response(array_map(fn () => [0.1, 0.2, 0.3, 0.4], $request['inputs']));
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/collections/knowledge')) {
                return Http::response(['status' => 'error'], 404);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), '/points')) {
                // The owner hits "delete" while the upsert is running.
                KnowledgeSource::query()->whereKey($source->id)->update(['status' => KnowledgeSourceStatus::Deleting->value]);
            }

            return Http::response(['result' => true, 'status' => 'ok']);
        });

        EmbedKnowledgeChunks::dispatchSync($source);

        $upserts = Http::recorded(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/points'));
        $this->assertCount(1, $upserts, 'stops after the first batch');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/points/delete?wait=true')
            && $r['filter']['must'][1] === ['key' => 'source_id', 'match' => ['value' => $source->ulid]]);
        $this->assertSame(KnowledgeSourceStatus::Deleting, $source->fresh()->status);
    }

    public function test_vectors_are_removed_when_the_source_row_is_gone_mid_batch(): void
    {
        $source = $this->sourceWithText('conteúdo');
        $source->update(['status' => KnowledgeSourceStatus::Embedding]);
        KnowledgeChunk::factory()->for($source, 'source')->create(['position' => 0]);

        Http::fake(function (Request $request) use ($source) {
            if (str_ends_with($request->url(), '/embed')) {
                return Http::response(array_map(fn () => [0.1, 0.2, 0.3, 0.4], $request['inputs']));
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/collections/knowledge')) {
                return Http::response(['status' => 'error'], 404);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), '/points')) {
                KnowledgeChunk::query()->where('knowledge_source_id', $source->id)->delete();
                KnowledgeSource::query()->whereKey($source->id)->delete();
            }

            return Http::response(['result' => true, 'status' => 'ok']);
        });

        EmbedKnowledgeChunks::dispatchSync($source);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/points/delete?wait=true'));
    }
}
