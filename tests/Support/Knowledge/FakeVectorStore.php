<?php

namespace Tests\Support\Knowledge;

use App\Contracts\VectorStore;
use Illuminate\Http\Client\ConnectionException;

/** In memory VectorStore: points keyed by id, so duplicates would be visible. */
class FakeVectorStore implements VectorStore
{
    /** @var array<string, array<string, mixed>> */
    public array $points = [];

    /** @var list<array{string, string}> */
    public array $deletedSources = [];

    public bool $down = false;

    public function ensureCollection(): void
    {
        $this->guard();
    }

    public function upsert(array $points): void
    {
        $this->guard();

        foreach ($points as $point) {
            $this->points[$point['id']] = $point;
        }
    }

    public function search(string $chatbotId, array $vector, int $limit, ?float $scoreThreshold = null): array
    {
        return [];
    }

    public function deleteBySource(string $chatbotId, string $sourceId): void
    {
        $this->guard();

        $this->deletedSources[] = [$chatbotId, $sourceId];
        $this->points = array_filter(
            $this->points,
            fn (array $p) => ! ($p['payload']['chatbot_id'] === $chatbotId && $p['payload']['source_id'] === $sourceId),
        );
    }

    public function deleteByChatbot(string $chatbotId): void
    {
        $this->guard();
    }

    private function guard(): void
    {
        if ($this->down) {
            throw new ConnectionException('cURL error 6: Could not resolve host: webapp_qdrant');
        }
    }
}
