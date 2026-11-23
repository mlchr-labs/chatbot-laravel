<?php

namespace Database\Factories;

use App\Enums\KnowledgeSourceStatus;
use App\Models\Chatbot;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeSource>
 */
class KnowledgeSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chatbot_id' => Chatbot::factory(),
            // Keeps the denormalized owner consistent with the chatbot.
            'user_id' => fn (array $attributes) => Chatbot::query()->findOrFail($attributes['chatbot_id'])->user_id,
            'original_name' => fake()->word().'.pdf',
            'disk_path' => 'knowledge/'.Str::uuid().'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(1_000, 5_000_000),
            'status' => KnowledgeSourceStatus::Pending,
            'chunks_count' => 0,
        ];
    }
}
