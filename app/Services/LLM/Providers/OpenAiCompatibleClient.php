<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\LlmRequest;
use App\Services\LLM\LlmResponse;

/** Chat Completions. Serves OpenAI, Groq and DeepSeek, which differ in base url and a few flags. */
class OpenAiCompatibleClient extends HttpLlmClient
{
    public function __construct(
        string $apiKey,
        string $baseUrl,
        string $defaultModel,
        int $timeout = 60,
        int $connectTimeout = 5,
        int $defaultMaxTokens = 1024,
        private readonly string $maxTokensParam = 'max_tokens',
        private readonly bool $sendTemperature = true,
        private readonly bool $streamUsage = true,
    ) {
        parent::__construct($apiKey, $baseUrl, $defaultModel, $timeout, $connectTimeout, $defaultMaxTokens);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $json = $this->postJson($this->url(), $this->payload($request));

        return new LlmResponse(
            content: (string) ($json['choices'][0]['message']['content'] ?? ''),
            tokensIn: (int) ($json['usage']['prompt_tokens'] ?? 0),
            tokensOut: (int) ($json['usage']['completion_tokens'] ?? 0),
            finishReason: $json['choices'][0]['finish_reason'] ?? null,
        );
    }

    public function stream(LlmRequest $request, callable $onToken): LlmResponse
    {
        $payload = $this->payload($request) + ['stream' => true];

        if ($this->streamUsage) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        $content = '';
        $tokensIn = 0;
        $tokensOut = 0;
        $finish = null;

        foreach ($this->postStream($this->url(), $payload) as ['json' => $chunk]) {
            $text = $chunk['choices'][0]['delta']['content'] ?? null;

            if (is_string($text) && $text !== '') {
                $content .= $text;
                $onToken($text);
            }

            $finish = $chunk['choices'][0]['finish_reason'] ?? $finish;

            // Usage comes in a final chunk (OpenAI include_usage), or under x_groq on Groq.
            $usage = $chunk['usage'] ?? $chunk['x_groq']['usage'] ?? null;

            if (is_array($usage)) {
                $tokensIn = (int) ($usage['prompt_tokens'] ?? $tokensIn);
                $tokensOut = (int) ($usage['completion_tokens'] ?? $tokensOut);
            }
        }

        return new LlmResponse($content, $tokensIn, $tokensOut, $finish);
    }

    /** @return array<string, string> */
    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiKey];
    }

    private function url(): string
    {
        return rtrim($this->baseUrl, '/').'/chat/completions';
    }

    /** @return array<string, mixed> */
    private function payload(LlmRequest $request): array
    {
        $payload = [
            'model' => $this->model($request),
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ...$request->messages,
            ],
            $this->maxTokensParam => $this->maxTokens($request),
        ];

        if ($this->sendTemperature && $request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        return $payload;
    }
}
