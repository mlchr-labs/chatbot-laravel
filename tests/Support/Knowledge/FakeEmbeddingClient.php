<?php

namespace Tests\Support\Knowledge;

use App\Contracts\EmbeddingClient;
use Illuminate\Http\Client\ConnectionException;

class FakeEmbeddingClient implements EmbeddingClient
{
    public bool $down = false;

    public int $calls = 0;

    public function embedPassages(array $texts): array
    {
        $this->calls++;

        if ($this->down) {
            throw new ConnectionException('cURL error 6: Could not resolve host: webapp_embeddings');
        }

        return array_map(fn () => [0.1, 0.2, 0.3, 0.4], array_values($texts));
    }

    public function embedQuery(string $text): array
    {
        return [0.1, 0.2, 0.3, 0.4];
    }
}
