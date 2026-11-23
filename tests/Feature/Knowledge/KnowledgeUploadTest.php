<?php

namespace Tests\Feature\Knowledge;

use App\Enums\KnowledgeSourceStatus;
use App\Jobs\Knowledge\ChunkKnowledgeText;
use App\Jobs\Knowledge\DeleteKnowledgeSource;
use App\Jobs\Knowledge\EmbedKnowledgeChunks;
use App\Jobs\Knowledge\ExtractKnowledgeText;
use App\Models\Chatbot;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    private Chatbot $chatbot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Bus::fake();

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
        $this->chatbot = Chatbot::factory()->for($this->owner)->create();
    }

    private function pdf(string $name = 'manual.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    public function test_upload_stores_the_file_privately_and_dispatches_the_chain(): void
    {
        $response = $this->actingAs($this->owner)->postJson(
            route('chatbots.sources.store', $this->chatbot),
            ['file' => $this->pdf('Meu Manual.pdf')],
        );

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Meu Manual.pdf')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.disk_path');

        $source = KnowledgeSource::query()->firstOrFail();
        $this->assertSame($this->chatbot->id, $source->chatbot_id);
        $this->assertSame($this->owner->id, $source->user_id);
        $this->assertSame('application/pdf', $source->mime);
        $this->assertSame(KnowledgeSourceStatus::Pending, $source->status);

        $this->assertMatchesRegularExpression(
            '#^knowledge/'.$this->owner->id.'/'.$this->chatbot->ulid.'/[0-9a-f-]{36}\.pdf$#',
            $source->disk_path,
        );
        $this->assertStringNotContainsString('Manual', $source->disk_path);
        Storage::disk('local')->assertExists($source->disk_path);

        Bus::assertChained([ExtractKnowledgeText::class, ChunkKnowledgeText::class, EmbedKnowledgeChunks::class]);
    }

    public function test_extension_comes_from_the_validated_type_and_name_is_sanitized(): void
    {
        $file = UploadedFile::fake()->createWithContent('../../etc/<b>passwd</b>.TXT', 'conteúdo simples');

        $this->actingAs($this->owner)
            ->postJson(route('chatbots.sources.store', $this->chatbot), ['file' => $file])
            ->assertCreated();

        $source = KnowledgeSource::query()->firstOrFail();
        $this->assertStringEndsWith('.txt', $source->disk_path);
        $this->assertStringNotContainsString('..', $source->disk_path);
        $this->assertStringNotContainsString('/', $source->original_name);
        $this->assertSame('text/plain', $source->mime);
    }

    public function test_unsupported_extension_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('chatbots.sources.store', $this->chatbot), [
                'file' => UploadedFile::fake()->createWithContent('malware.exe', 'MZ...'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('knowledge_sources', 0);
        Bus::assertNothingDispatched();
    }

    public function test_file_over_the_size_limit_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('chatbots.sources.store', $this->chatbot), [
                'file' => UploadedFile::fake()->create('big.pdf', 16 * 1024 + 1, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_php_content_with_a_pdf_extension_is_rejected(): void
    {
        $spoof = UploadedFile::fake()->createWithContent('invoice.pdf', "<?php system(\$_GET['c']); ?>\n", 'application/pdf');

        $this->actingAs($this->owner)
            ->postJson(route('chatbots.sources.store', $this->chatbot), ['file' => $spoof])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('knowledge_sources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pdf_content_with_a_txt_extension_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('chatbots.sources.store', $this->chatbot), ['file' => $this->pdf('fake.txt')])
            ->assertUnprocessable();
    }

    public function test_status_endpoint_lists_only_this_chatbots_visible_sources(): void
    {
        $mine = KnowledgeSource::factory()->for($this->chatbot)->create(['original_name' => 'meu.pdf']);
        KnowledgeSource::factory()->for($this->chatbot)->create(['original_name' => 'sumindo.pdf', 'status' => KnowledgeSourceStatus::Deleting]);
        $otherBot = Chatbot::factory()->for($this->owner)->create();
        KnowledgeSource::factory()->for($otherBot)->create(['original_name' => 'outro-bot.pdf']);
        KnowledgeSource::factory()->for(Chatbot::factory()->for($this->intruder))->create(['original_name' => 'alheio.pdf']);

        $response = $this->actingAs($this->owner)->getJson(route('chatbots.sources.index', $this->chatbot));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $mine->ulid)
            ->assertJsonPath('data.0.processing', true);
    }

    public function test_destroy_hides_the_source_immediately_and_dispatches_the_delete_job(): void
    {
        $source = KnowledgeSource::factory()->for($this->chatbot)->create();

        $this->actingAs($this->owner)
            ->deleteJson(route('chatbots.sources.destroy', [$this->chatbot, $source]))
            ->assertAccepted();

        $this->assertSame(KnowledgeSourceStatus::Deleting, $source->fresh()->status);
        Bus::assertDispatched(DeleteKnowledgeSource::class, fn ($job) => $job->source->is($source) && $job->queue === 'knowledge');

        $this->actingAs($this->owner)
            ->getJson(route('chatbots.sources.index', $this->chatbot))
            ->assertJsonCount(0, 'data');
    }

    public function test_show_page_renders_the_knowledge_tab(): void
    {
        KnowledgeSource::factory()->for($this->chatbot)->create(['original_name' => 'catalogo.pdf']);

        $this->actingAs($this->owner)
            ->get(route('chatbots.show', $this->chatbot))
            ->assertOk()
            ->assertSee('catalogo.pdf')
            ->assertSee('knowledgeSources(', false);
    }

    public function test_another_user_cannot_upload_to_list_or_delete(): void
    {
        $source = KnowledgeSource::factory()->for($this->chatbot)->create();

        $this->actingAs($this->intruder)
            ->postJson(route('chatbots.sources.store', $this->chatbot), ['file' => $this->pdf()])
            ->assertNotFound();

        // Not even validation feedback leaks for a chatbot the user does not own.
        $this->actingAs($this->intruder)
            ->postJson(route('chatbots.sources.store', $this->chatbot), [])
            ->assertNotFound();

        $this->actingAs($this->intruder)
            ->getJson(route('chatbots.sources.index', $this->chatbot))
            ->assertNotFound();

        $this->actingAs($this->intruder)
            ->deleteJson(route('chatbots.sources.destroy', [$this->chatbot, $source]))
            ->assertNotFound();

        $this->assertDatabaseCount('knowledge_sources', 1);
        $this->assertSame(KnowledgeSourceStatus::Pending, $source->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Bus::assertNothingDispatched();
    }

    public function test_intruder_cannot_delete_a_foreign_source_through_their_own_chatbot(): void
    {
        $source = KnowledgeSource::factory()->for($this->chatbot)->create();
        $mine = Chatbot::factory()->for($this->intruder)->create();

        $this->actingAs($this->intruder)
            ->deleteJson(route('chatbots.sources.destroy', [$mine, $source]))
            ->assertNotFound();

        $this->assertSame(KnowledgeSourceStatus::Pending, $source->fresh()->status);
        Bus::assertNotDispatched(DeleteKnowledgeSource::class);
    }

    public function test_source_of_chatbot_x_under_chatbot_y_is_404_even_for_the_owner(): void
    {
        $x = Chatbot::factory()->for($this->owner)->create();
        $source = KnowledgeSource::factory()->for($x)->create();

        $this->actingAs($this->owner)
            ->deleteJson(route('chatbots.sources.destroy', [$this->chatbot, $source]))
            ->assertNotFound();

        $this->assertSame(KnowledgeSourceStatus::Pending, $source->fresh()->status);

        $this->actingAs($this->owner)
            ->deleteJson(route('chatbots.sources.destroy', [$x, $source]))
            ->assertAccepted();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->post(route('chatbots.sources.store', $this->chatbot))->assertRedirect(route('login'));
        $this->get(route('chatbots.sources.index', $this->chatbot))->assertRedirect(route('login'));
    }
}
