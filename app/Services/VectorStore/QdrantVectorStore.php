<?php

namespace App\Services\VectorStore;

use App\Contracts\VectorStore;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class QdrantVectorStore implements VectorStore
{
    private const ENSURED_TTL_SECONDS = 3600;

    private bool $ensured = false;

    public function __construct(
        private readonly string $url,
        private readonly ?string $apiKey,
        private readonly string $collection,
        private readonly int $dimensions,
        private readonly Cache $cache,
        private readonly int $timeout = 15,
    ) {}

    public function ensureCollection(): void
    {
        $cacheKey = "qdrant:collection-ready:{$this->collection}:{$this->dimensions}";

        if ($this->ensured || $this->cache->get($cacheKey)) {
            $this->ensured = true;

            return;
        }

        $exists = $this->request()->get("/collections/{$this->collection}");

        if ($exists->status() === 404) {
            $this->request()->put("/collections/{$this->collection}", [
                'vectors' => ['size' => $this->dimensions, 'distance' => 'Cosine'],
            ])->throw();
        } else {
            $exists->throw();
        }

        // Creating an index that already exists is a no-op in Qdrant.
        $this->request()->put("/collections/{$this->collection}/index?wait=true", [
            'field_name' => 'chatbot_id',
            'field_schema' => ['type' => 'keyword', 'is_tenant' => true],
        ])->throw();

        $this->request()->put("/collections/{$this->collection}/index?wait=true", [
            'field_name' => 'source_id',
            'field_schema' => 'keyword',
        ])->throw();

        $this->ensured = true;
        $this->cache->put($cacheKey, true, self::ENSURED_TTL_SECONDS);
    }

    public function upsert(array $points): void
    {
        if ($points === []) {
            return;
        }

        $this->request()->put("/collections/{$this->collection}/points?wait=true", [
            'points' => array_map(fn (array $point) => [
                'id' => $point['id'],
                'vector' => $point['vector'],
                'payload' => $point['payload'],
            ], array_values($points)),
        ])->throw();
    }

    public function search(string $chatbotId, array $vector, int $limit, ?float $scoreThreshold = null): array
    {
        $this->assertTenant($chatbotId);

        $body = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
            'filter' => ['must' => [$this->match('chatbot_id', $chatbotId)]],
        ];

        if ($scoreThreshold !== null) {
            $body['score_threshold'] = $scoreThreshold;
        }

        $result = $this->request()->post("/collections/{$this->collection}/points/search", $body)
            ->throw()
            ->json('result', []);

        return array_map(fn (array $hit) => [
            'id' => (string) $hit['id'],
            'score' => (float) $hit['score'],
            'payload' => $hit['payload'] ?? [],
        ], $result);
    }

    public function deleteBySource(string $chatbotId, string $sourceId): void
    {
        $this->assertTenant($chatbotId);

        if ($sourceId === '') {
            throw new InvalidArgumentException('A source id is required to delete vectors.');
        }

        $this->request()->post("/collections/{$this->collection}/points/delete?wait=true", [
            'filter' => ['must' => [
                $this->match('chatbot_id', $chatbotId),
                $this->match('source_id', $sourceId),
            ]],
        ])->throw();
    }

    public function deleteByChatbot(string $chatbotId): void
    {
        $this->assertTenant($chatbotId);

        $this->request()->post("/collections/{$this->collection}/points/delete?wait=true", [
            'filter' => ['must' => [$this->match('chatbot_id', $chatbotId)]],
        ])->throw();
    }

    /**
     * @return array{key: string, match: array{value: string}}
     */
    private function match(string $key, string $value): array
    {
        return ['key' => $key, 'match' => ['value' => $value]];
    }

    private function assertTenant(string $chatbotId): void
    {
        if (trim($chatbotId) === '') {
            throw new InvalidArgumentException('A chatbot id is required: vector operations are always tenant scoped.');
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->url, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(5);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withHeaders(['api-key' => $this->apiKey]);
        }

        return $request;
    }
}
