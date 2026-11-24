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

    public function test_form_orders_provider_key_then_model_and_temperature(): void
    {
        $html = $this->actingAs($this->user)->get(route('chatbots.create'))->assertOk()->getContent();

        $positions = array_map(fn (string $needle) => strpos($html, $needle), [
            'for="provider"', 'for="api_key"', 'for="model"', 'for="temperature"',
        ]);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        // Provider and key share the first row, model and temperature the second.
        $this->assertSame(2, substr_count($html, 'grid gap-6 sm:grid-cols-2'));
    }

    public function test_model_is_always_a_select_with_a_custom_option_and_no_load_button(): void
    {
        $html = $this->actingAs($this->user)->get(route('chatbots.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select\s+name="model"\s+id="model"/', $html);
        $this->assertStringContainsString('<option value="__custom__">Outro modelo…</option>', $html);
        // The free text fallback is submitted in the same field, and starts disabled.
        $this->assertMatchesRegularExpression('/<input type="text" name="model" id="model-custom"[^>]* disabled/', $html);
        $this->assertStringNotContainsString('<datalist', $html);
        $this->assertStringNotContainsString('Carregar modelos', $html);
        // Auto load on paste and blur of the key field.
        $this->assertMatchesRegularExpression('/name="api_key"[^>]*x-on:paste="schedule\(\)"[^>]*x-on:blur="schedule\(\)"/', $html);
    }

    public function test_page_embeds_the_provider_labels_for_the_unavailable_model_warning(): void
    {
        $this->actingAs($this->user)->get(route('chatbots.create'))
            ->assertOk()
            ->assertSee('\u0022groq\u0022:\u0022Groq\u0022', false);
    }

    public function test_edit_page_hands_the_saved_model_to_the_picker_even_when_the_platform_dropped_it(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('sk-very-secret')->create(['provider' => 'groq', 'model' => 'llama-3.3-70b-versatile']);

        $this->actingAs($this->user)->get(route('chatbots.edit', $chatbot))
            ->assertOk()
            ->assertSee("model: 'llama-3.3-70b-versatile'", false)
            ->assertSee('hasSavedKey: true', false)
            ->assertDontSee('sk-very-secret');
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
