<?php

namespace Database\Factories;

use App\Models\Chatbot;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chatbot_id' => Chatbot::factory(),
            'visitor_id' => Str::uuid()->toString(),
            'origin' => 'https://example.com',
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'last_message_at' => now(),
        ];
    }
}
