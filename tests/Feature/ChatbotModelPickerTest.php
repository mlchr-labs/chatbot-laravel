<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotModelPickerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->user = User::factory()->create();
        config([
            'llm.providers.groq.model' => 'llama-default',
            'llm.platform_models.groq' => ['llama-default', 'llama-other'],
            'llm.platform_models.openai' => ['gpt-test'],
        ]);
    }

    public function test_create_page_embeds_the_platform_models_of_every_provider(): void
    {
        $html = $this->actingAs($this->user)->get(route('chatbots.create'))->assertOk()->getContent();

        $this->assertStringContainsString('modelPicker(', $html);
        // @js escapes quotes as \u0022 inside the attribute.
        $this->assertStringContainsString('\u0022groq\u0022:{\u0022default\u0022:\u0022llama-default\u0022,\u0022models\u0022:[\u0022llama-default\u0022,\u0022llama-other\u0022]}', $html);
        $this->assertStringContainsString(str_replace('/', '\\/', route('chatbots.models')), $html);
        // The hardcoded placeholder is gone: it now follows the provider.
        $this->assertStringNotContainsString('placeholder="gpt-4o-mini"', $html);
    }

    public function test_edit_page_embeds_the_catalog_without_leaking_the_saved_key(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('sk-very-secret')->create();

        $this->actingAs($this->user)->get(route('chatbots.edit', $chatbot))
            ->assertOk()
            ->assertSee('modelPicker(', false)
            ->assertSee($chatbot->ulid)
            ->assertDontSee('sk-very-secret');
    }

    public function test_platform_key_still_only_accepts_listed_models(): void
    {
        $payload = [
            'name' => 'Bot', 'provider' => 'groq', 'model' => 'llama-not-listed',
            'temperature' => 0.5, 'allowed_domains' => '', 'is_active' => 1,
        ];

        $this->actingAs($this->user)->post(route('chatbots.store'), $payload)->assertSessionHasErrors('model');
        $this->assertSame(0, Chatbot::count());

        $this->actingAs($this->user)->post(route('chatbots.store'), ['model' => 'llama-other'] + $payload)->assertSessionHasNoErrors();
        $this->assertSame('llama-other', Chatbot::firstOrFail()->model);
    }

    public function test_own_key_accepts_a_model_outside_the_platform_list(): void
    {
        $this->actingAs($this->user)->post(route('chatbots.store'), [
            'name' => 'Bot', 'provider' => 'groq', 'model' => 'llama-not-listed', 'api_key' => 'gsk-own',
            'temperature' => 0.5, 'allowed_domains' => '', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('llama-not-listed', Chatbot::firstOrFail()->model);
    }
}
