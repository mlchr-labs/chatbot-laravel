<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => MessageRole::User,
            'content' => fake()->sentence(),
            'tokens_in' => null,
            'tokens_out' => null,
            'sources' => null,
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn () => ['role' => MessageRole::Assistant]);
    }
}
