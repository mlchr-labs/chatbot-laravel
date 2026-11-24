<?php

namespace App\Services\LLM\Catalogs;

use App\Rules\ChatbotModel;
use App\Services\LLM\Contracts\ModelCatalog;
use App\Services\LLM\Exceptions\LlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Shared transport for the model listings. The api key only travels in a header and
 * provider error bodies are discarded: only the status maps to a safe LlmException.
 */
abstract class HttpModelCatalog implements ModelCatalog
{
    /** Safety net against a provider that never stops returning a next page. */
    protected const MAX_PAGES = 10;

    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $baseUrl,
        protected readonly int $timeout = 60,
        protected readonly int $connectTimeout = 5,
    ) {}

    /** @return array<string, string> */
    abstract protected function headers(): array;

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function getJson(string $path, array $query = []): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->acceptJson()
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->get(rtrim($this->baseUrl, '/').$path, $query);
        } catch (ConnectionException) {
            throw LlmException::unreachable();
        }

        if (! $response->successful()) {
            throw LlmException::fromStatus($response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : throw LlmException::invalidResponse();
    }

    /**
     * Keeps only ids the chatbot form would accept, without duplicates.
     *
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    protected function clean(array $ids): array
    {
        $valid = array_filter(
            $ids,
            static fn (mixed $id): bool => is_string($id)
                && $id !== ''
                && mb_strlen($id) <= ChatbotModel::MAX_LENGTH
                && preg_match(ChatbotModel::BYOK_PATTERN, $id) === 1,
        );

        return array_values(array_unique($valid));
    }
}
