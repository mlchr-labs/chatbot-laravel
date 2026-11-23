<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Hugging Face Text Embeddings Inference (POST /embed) with intfloat/multilingual-e5 style prefixes.
 */
class TeiEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private readonly string $url,
        private readonly int $dimensions,
        private readonly int $batchSize = 32,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 5,
    ) {}

    public function embedPassages(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk(array_values($texts), max(1, $this->batchSize)) as $batch) {
            $prefixed = array_map(fn (string $text) => 'passage: '.$text, $batch);

            foreach ($this->embed($prefixed) as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    public function embedQuery(string $text): array
    {
        return $this->embed(['query: '.$text])[0];
    }

    /**
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    private function embed(array $inputs): array
    {
        $response = $this->request()->post('/embed', [
            'inputs' => $inputs,
            'truncate' => true,
        ])->throw();

        $vectors = $response->json();

        if (! is_array($vectors) || count($vectors) !== count($inputs)) {
            throw new RuntimeException('Embedding service returned an unexpected number of vectors.');
        }

        foreach ($vectors as $vector) {
            if (! is_array($vector) || count($vector) !== $this->dimensions) {
                throw new RuntimeException(sprintf(
                    'Embedding dimension mismatch: expected %d, got %s.',
                    $this->dimensions,
                    is_array($vector) ? count($vector) : 'non-array',
                ));
            }
        }

        return array_map(fn (array $vector) => array_map('floatval', array_values($vector)), array_values($vectors));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->url, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);
    }
}
