<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\Contracts\LlmClient;
use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\LlmRequest;
use App\Services\LLM\SseReader;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared transport. Subclasses never log or echo the api key, and provider error bodies
 * are discarded: only the status code is mapped to a safe LlmException.
 */
abstract class HttpLlmClient implements LlmClient
{
    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $baseUrl,
        protected readonly string $defaultModel,
        protected readonly int $timeout = 60,
        protected readonly int $connectTimeout = 5,
        protected readonly int $defaultMaxTokens = 1024,
    ) {}

    /** @return array<string, string> */
    abstract protected function headers(): array;

    protected function model(LlmRequest $request): string
    {
        return $request->model !== null && $request->model !== '' ? $request->model : $this->defaultModel;
    }

    protected function maxTokens(LlmRequest $request): int
    {
        return $request->maxTokens ?? $this->defaultMaxTokens;
    }

    protected function http(bool $stream = false): PendingRequest
    {
        return Http::withHeaders($this->headers())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions($stream ? ['stream' => true] : []);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function postJson(string $url, array $body): array
    {
        try {
            $response = $this->http()->post($url, $body);
        } catch (ConnectionException) {
            throw LlmException::unreachable();
        }

        $this->ensureSuccessful($response);

        $json = $response->json();

        return is_array($json) ? $json : throw LlmException::invalidResponse();
    }

    /**
     * Yields the decoded JSON payload of each SSE event (event name included) of a streaming call.
     *
     * @param  array<string, mixed>  $body
     * @return Generator<int, array{event: string|null, json: array<string, mixed>}>
     */
    protected function postStream(string $url, array $body): Generator
    {
        try {
            $response = $this->http(stream: true)->post($url, $body);
        } catch (ConnectionException) {
            throw LlmException::unreachable();
        }

        $this->ensureSuccessful($response);

        try {
            foreach (SseReader::events($response->toPsrResponse()->getBody()) as $event) {
                if ($event['data'] === '[DONE]') {
                    return;
                }

                $json = json_decode($event['data'], true);

                if (is_array($json)) {
                    yield ['event' => $event['event'], 'json' => $json];
                }
            }
        } catch (LlmException $e) {
            throw $e;
        } catch (Throwable) {
            // Read timeout or dropped connection in the middle of the stream.
            throw LlmException::unreachable();
        }
    }

    protected function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw LlmException::fromStatus($response->status());
    }
}
