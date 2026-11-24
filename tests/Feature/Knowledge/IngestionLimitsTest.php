<?php

namespace Tests\Feature\Knowledge;

use App\Actions\Knowledge\DispatchKnowledgeIngestion;
use App\Enums\KnowledgeFailureReason;
use App\Enums\KnowledgeSourceStatus;
use App\Models\Chatbot;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IngestionLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Chatbot $chatbot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->chatbot = Chatbot::factory()->for($this->owner)->create();
    }

    private function upload(?Chatbot $chatbot = null, string $content = "texto simples\n")
    {
        return $this->actingAs($this->owner)->postJson(
            route('chatbots.sources.store', $chatbot ?? $this->chatbot),
            ['file' => UploadedFile::fake()->createWithContent('doc.txt', $content)],
        );
    }

    public function test_source_count_cap_applies_across_all_chatbots_of_the_user(): void
    {
        Bus::fake();
        config(['plans.max_knowledge_sources_per_user' => 2]);
        $other = Chatbot::factory()->for($this->owner)->create();
        KnowledgeSource::factory()->for($this->chatbot)->create();
        KnowledgeSource::factory()->for($other)->create();

        $this->upload()->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Você atingiu o limite de 2 documentos. Exclua algum para enviar outro.');
        $this->assertSame(2, KnowledgeSource::count());
    }

    public function test_sources_being_deleted_and_other_users_do_not_count(): void
    {
        Bus::fake();
        config(['plans.max_knowledge_sources_per_user' => 1]);
        KnowledgeSource::factory()->for($this->chatbot)->create(['status' => KnowledgeSourceStatus::Deleting]);
        KnowledgeSource::factory()->for(Chatbot::factory()->create())->create();

        $this->upload()->assertCreated();
    }

    public function test_total_bytes_cap(): void
    {
        Bus::fake();
        config(['plans.max_knowledge_bytes_per_user' => 1024 * 1024]);
        KnowledgeSource::factory()->for($this->chatbot)->create(['size' => 1024 * 1024 - 5]);

        $this->upload(content: "mais de cinco bytes\n")->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Você atingiu o limite de armazenamento de documentos (1 MB). Exclua algum para enviar outro.');
        Storage::disk('local')->assertDirectoryEmpty('knowledge');
    }

    public function test_upload_endpoint_is_throttled_per_user(): void
    {
        Bus::fake();
        config(['knowledge.uploads_per_minute_user' => 2]);

        $this->upload()->assertCreated();
        $this->upload()->assertCreated();
        $this->upload()->assertStatus(429);

        // Another user has their own bucket.
        $stranger = User::factory()->create();
        $mine = Chatbot::factory()->for($stranger)->create();
        $this->actingAs($stranger)->postJson(
            route('chatbots.sources.store', $mine),
            ['file' => UploadedFile::fake()->createWithContent('doc.txt', "ok\n")],
        )->assertCreated();
    }

    private function runChain(string $text): KnowledgeSource
    {
        Http::fake(['*' => Http::response(['result' => true], 200)]);
        $path = "knowledge/{$this->owner->id}/{$this->chatbot->ulid}/file.txt";
        Storage::disk('local')->put($path, $text);
        $source = KnowledgeSource::factory()->for($this->chatbot)->create(['disk_path' => $path, 'mime' => 'text/plain']);

        try {
            app(DispatchKnowledgeIngestion::class)($source);
        } catch (\Throwable) {
            // Sync queue rethrows after the failure callback ran.
        }

        return $source->refresh();
    }

    public function test_extracted_text_over_the_cap_fails_the_source_safely(): void
    {
        config(['knowledge.max_extracted_chars' => 100]);

        $source = $this->runChain(str_repeat('palavra ', 30));

        $this->assertSame(KnowledgeSourceStatus::Failed, $source->status);
        $this->assertSame(KnowledgeFailureReason::DocumentTooLarge, $source->failure_reason);
        $this->assertSame(0, KnowledgeChunk::count());
        Http::assertNothingSent();
    }

    public function test_text_at_the_cap_is_accepted_by_the_extraction_step(): void
    {
        config(['knowledge.max_extracted_chars' => 8, 'knowledge.max_chunks_per_source' => 5000]);

        $source = $this->runChain('12345678');

        $this->assertNotSame(KnowledgeFailureReason::DocumentTooLarge, $source->failure_reason);
    }

    public function test_too_many_chunks_fails_the_source_safely(): void
    {
        config(['knowledge.max_chunks_per_source' => 2, 'knowledge.chunk_size' => 100, 'knowledge.chunk_overlap' => 10]);
        $paragraphs = implode("\n\n", array_map(fn ($i) => "Parágrafo {$i} ".str_repeat('texto ', 20), range(1, 10)));

        $source = $this->runChain($paragraphs);

        $this->assertSame(KnowledgeSourceStatus::Failed, $source->status);
        $this->assertSame(KnowledgeFailureReason::DocumentTooLarge, $source->failure_reason);
        $this->assertSame(0, KnowledgeChunk::count());
        Http::assertNothingSent();
    }
}
