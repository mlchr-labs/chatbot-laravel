<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->user = User::factory()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Suporte',
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'temperature' => 0.3,
            'allowed_domains' => 'example.com',
            'is_active' => 1,
        ], $overrides);
    }

    public function test_user_cannot_create_more_chatbots_than_the_cap(): void
    {
        config(['plans.max_chatbots_per_user' => 2, 'llm.platform_models.openai' => ['gpt-6-luna']]);
        Chatbot::factory()->for($this->user)->count(2)->create();

        $this->actingAs($this->user)->get(route('chatbots.create'))
            ->assertOk()
            ->assertSee('Você atingiu o limite de 2 chatbots.')
            ->assertDontSee('Criar chatbot');

        $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload())
            ->assertRedirect(route('chatbots.create'));

        $this->assertSame(2, Chatbot::count());
    }

    public function test_cap_is_per_user(): void
    {
        config(['plans.max_chatbots_per_user' => 1, 'llm.platform_models.openai' => ['gpt-6-luna']]);
        Chatbot::factory()->for(User::factory()->create())->create();

        $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload())->assertRedirect();

        $this->assertSame(1, $this->user->chatbots()->count());
    }

    public function test_platform_key_requires_an_allowlisted_model(): void
    {
        config(['llm.platform_models.openai' => ['gpt-6-luna']]);

        $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload(['model' => 'gpt-expensive']))
            ->assertSessionHasErrors('model');
        $this->assertSame(0, Chatbot::count());

        $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload(['model' => 'gpt-6-luna']))
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Chatbot::count());
    }

    public function test_byok_allows_any_model_within_the_charset(): void
    {
        config(['llm.platform_models.openai' => ['gpt-6-luna']]);

        $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload([
            'api_key' => 'sk-mine', 'model' => 'ft:gpt-x/custom_1.2-beta',
        ]))->assertSessionDoesntHaveErrors();
        $this->assertSame('ft:gpt-x/custom_1.2-beta', Chatbot::firstOrFail()->model);
    }

    public function test_byok_rejects_unsafe_or_long_models(): void
    {
        foreach (['bad model', "a\nb", 'm?x=1', 'a#b', str_repeat('a', 101)] as $model) {
            $this->actingAs($this->user)->post(route('chatbots.store'), $this->payload([
                'api_key' => 'sk-mine', 'model' => $model,
            ]))->assertSessionHasErrors('model');
        }

        $this->assertSame(0, Chatbot::count());
    }

    public function test_update_uses_the_stored_key_to_pick_the_rule(): void
    {
        config(['llm.platform_models.openai' => ['gpt-6-luna']]);
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('sk-keep')->create(['provider' => 'openai']);

        // Keeps own key: free-form model is fine.
        $this->actingAs($this->user)->put(route('chatbots.update', $chatbot), $this->payload(['model' => 'custom-model', 'api_key' => '']))
            ->assertSessionDoesntHaveErrors();

        // Removing the key puts it back on the platform key and its allowlist.
        $this->actingAs($this->user)->put(route('chatbots.update', $chatbot), $this->payload(['model' => 'custom-model', 'remove_api_key' => 1]))
            ->assertSessionHasErrors('model');
    }
}
