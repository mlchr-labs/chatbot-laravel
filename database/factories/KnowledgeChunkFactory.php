<?php

namespace Database\Factories;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeChunk>
 */
class KnowledgeChunkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'knowledge_source_id' => KnowledgeSource::factory(),
            'chatbot_id' => fn (array $attributes) => KnowledgeSource::query()->findOrFail($attributes['knowledge_source_id'])->chatbot_id,
            'position' => 0,
            'content' => fake()->paragraph(),
            'embedded_at' => null,
        ];
    }
}
