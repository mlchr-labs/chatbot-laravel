<?php

namespace Database\Factories;

use App\Enums\LlmProvider;
use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chatbot>
 */
class ChatbotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'system_prompt' => 'Você é um assistente prestativo.',
            'provider' => LlmProvider::OpenAi,
            'model' => 'gpt-4o-mini',
            'api_key' => null,
            'temperature' => 0.7,
            'welcome_message' => 'Olá! Como posso ajudar?',
            'allowed_domains' => [],
            'is_active' => true,
        ];
    }

    public function withApiKey(string $key = 'sk-test-secret-key'): static
    {
        return $this->state(fn () => ['api_key' => $key]);
    }
}
