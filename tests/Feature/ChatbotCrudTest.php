<?php

namespace Tests\Feature;

use App\Jobs\Knowledge\PurgeChatbotKnowledge;
use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ChatbotCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->user = User::factory()->create();
        config(['llm.platform_models.anthropic' => ['claude-test']]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Suporte',
            'system_prompt' => 'Seja gentil.',
            'provider' => 'anthropic',
            'model' => 'claude-test',
            'temperature' => 0.3,
            'welcome_message' => 'Oi!',
            'allowed_domains' => "Example.com\nwww.example.com",
            'is_active' => 1,
        ], $overrides);
    }

    public function test_index_shows_empty_state(): void
    {
        $this->actingAs($this->user)->get(route('chatbots.index'))
            ->assertOk()
            ->assertSee('Nenhum chatbot por aqui ainda');
    }

    public function test_index_lists_chatbots(): void
    {
        Chatbot::factory()->for($this->user)->create(['name' => 'Meu bot']);

        $this->actingAs($this->user)->get(route('chatbots.index'))
            ->assertOk()
            ->assertSee('Meu bot');
    }

    public function test_create_form_renders(): void
    {
        $this->actingAs($this->user)->get(route('chatbots.create'))->assertOk();
    }

    public function test_store_creates_chatbot_owned_by_authenticated_user(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload([
            'api_key' => 'sk-secret',
            'user_id' => $other->id,
            'public_key' => 'pk_forged',
            'ulid' => 'forged',
        ]));

        $chatbot = Chatbot::query()->firstOrFail();
        $response->assertRedirect(route('chatbots.show', $chatbot));

        $this->assertSame($this->user->id, $chatbot->user_id);
        $this->assertNotSame('forged', $chatbot->ulid);
        $this->assertSame(26, strlen($chatbot->ulid));
        $this->assertStringStartsWith('pk_', $chatbot->public_key);
        $this->assertSame(35, strlen($chatbot->public_key));
        $this->assertNotSame('pk_forged', $chatbot->public_key);
        $this->assertSame(['example.com', 'www.example.com'], $chatbot->allowed_domains);
        $this->assertSame('sk-secret', $chatbot->api_key);
        $this->assertNotSame('sk-secret', $chatbot->getRawOriginal('api_key'));
    }

    public function test_show_renders_snippet_with_public_key(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->create();

        $this->actingAs($this->user)->get(route('chatbots.show', $chatbot))
            ->assertOk()
            ->assertSee(e('data-chatbot="'.$chatbot->public_key.'"'), false)
            ->assertSee(e('<script src="'.url('widget.js').'"'), false);
    }

    public function test_edit_never_echoes_the_api_key(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('sk-very-secret')->create();

        $this->actingAs($this->user)->get(route('chatbots.edit', $chatbot))
            ->assertOk()
            ->assertDontSee('sk-very-secret')
            ->assertSee('Chave configurada', false);

        $this->actingAs($this->user)->get(route('chatbots.show', $chatbot))
            ->assertOk()
            ->assertDontSee('sk-very-secret');
    }

    public function test_update_changes_fields_and_keeps_api_key_when_input_is_empty(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('sk-keep')->create();

        $this->actingAs($this->user)->put(route('chatbots.update', $chatbot), $this->payload([
            'name' => 'Renomeado',
            'api_key' => '',
        ]))->assertRedirect(route('chatbots.show', $chatbot));

        $chatbot->refresh();
        $this->assertSame('Renomeado', $chatbot->name);
        $this->assertSame('sk-keep', $chatbot->api_key);
    }

    public function test_update_replaces_api_key_when_provided(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('sk-old')->create();

        $this->actingAs($this->user)->put(route('chatbots.update', $chatbot), $this->payload(['api_key' => 'sk-new']));

        $this->assertSame('sk-new', $chatbot->fresh()->api_key);
    }

    public function test_update_removes_api_key_with_explicit_checkbox(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('sk-old')->create();

        $this->actingAs($this->user)->put(route('chatbots.update', $chatbot), $this->payload(['remove_api_key' => 1]));

        $this->assertNull($chatbot->fresh()->api_key);
        $this->assertFalse($chatbot->fresh()->hasApiKey());
    }

    public function test_update_cannot_change_owner(): void
    {
        $other = User::factory()->create();
        $chatbot = Chatbot::factory()->for($this->user)->create();

        $this->actingAs($this->user)->put(route('chatbots.update', $chatbot), $this->payload(['user_id' => $other->id]));

        $this->assertSame($this->user->id, $chatbot->fresh()->user_id);
    }

    public function test_destroy_deletes_chatbot(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->create();

        Bus::fake();

        $this->actingAs($this->user)->delete(route('chatbots.destroy', $chatbot))
            ->assertRedirect(route('chatbots.index'));

        $this->assertModelMissing($chatbot);

        // The row is gone, so the purge carries the identifiers captured before deleting.
        Bus::assertDispatched(PurgeChatbotKnowledge::class, fn (PurgeChatbotKnowledge $job) => $job->chatbotUlid === $chatbot->ulid
            && $job->userId === $this->user->id);
    }

    public function test_rotate_key_changes_public_key(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->create();
        $old = $chatbot->public_key;

        $this->actingAs($this->user)->post(route('chatbots.rotate-key', $chatbot))->assertRedirect();

        $new = $chatbot->fresh()->public_key;
        $this->assertNotSame($old, $new);
        $this->assertStringStartsWith('pk_', $new);
    }

    public function test_allowed_domains_rejects_urls_with_scheme_and_path(): void
    {
        $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload([
            'allowed_domains' => 'https://x.com/path',
        ]))->assertSessionHasErrors('allowed_domains.0');

        $this->assertDatabaseCount('chatbots', 0);
    }

    public function test_allowed_domains_accepts_array_input(): void
    {
        $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload([
            'allowed_domains' => ['A.com', 'b.com'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['a.com', 'b.com'], Chatbot::query()->firstOrFail()->allowed_domains);
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload([
            'name' => '',
            'provider' => 'skynet',
            'model' => '',
            'temperature' => 3,
            'system_prompt' => str_repeat('a', 8001),
        ]))->assertSessionHasErrors(['name', 'provider', 'model', 'temperature', 'system_prompt']);
    }

    public function test_conversations_pages_render_for_owner(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->create();

        $this->actingAs($this->user)->get(route('chatbots.conversations.index', $chatbot))
            ->assertOk()
            ->assertSee('Nenhuma conversa ainda');
    }
}
