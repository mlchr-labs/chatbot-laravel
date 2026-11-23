<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\LlmRequest;
use App\Services\LLM\LlmResponse;

/** Messages API. Current Claude models reject temperature/top_p/top_k, so sampling is never sent. */
class AnthropicClient extends HttpLlmClient
{
    public function __construct(
        string $apiKey,
        string $baseUrl,
        string $defaultModel,
        int $timeout = 60,
        int $connectTimeout = 5,
        int $defaultMaxTokens = 1024,
        private readonly string $version = '2026-10-21',
    ) {
        parent::__construct($apiKey, $baseUrl, $defaultModel, $timeout, $connectTimeout, $defaultMaxTokens);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $json = $this->postJson($this->url(), $this->payload($request));

        $text = '';
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return new LlmResponse(
            content: $text,
            tokensIn: (int) ($json['usage']['input_tokens'] ?? 0),
            tokensOut: (int) ($json['usage']['output_tokens'] ?? 0),
            finishReason: $json['stop_reason'] ?? null,
        );
    }

    public function stream(LlmRequest $request, callable $onToken): LlmResponse
    {
        $content = '';
        $tokensIn = 0;
        $tokensOut = 0;
        $finish = null;

        foreach ($this->postStream($this->url(), $this->payload($request) + ['stream' => true]) as ['json' => $event]) {
            switch ($event['type'] ?? null) {
                case 'message_start':
                    $tokensIn = (int) ($event['message']['usage']['input_tokens'] ?? 0);
                    $tokensOut = (int) ($event['message']['usage']['output_tokens'] ?? 0);
                    break;

                case 'content_block_delta':
                    if (($event['delta']['type'] ?? null) === 'text_delta') {
                        $text = (string) ($event['delta']['text'] ?? '');

                        if ($text !== '') {
                            $content .= $text;
                            $onToken($text);
                        }
                    }
                    break;

                case 'message_delta':
                    // Usage here is cumulative, so it replaces the earlier value.
                    $tokensOut = (int) ($event['usage']['output_tokens'] ?? $tokensOut);
                    $tokensIn = (int) ($event['usage']['input_tokens'] ?? $tokensIn);
                    $finish = $event['delta']['stop_reason'] ?? $finish;
                    break;

                case 'error':
                    // Mid-stream error event (for example overloaded_error). The body is not exposed.
                    throw new LlmException('O provedor de IA interrompeu a resposta.');
            }
        }

        return new LlmResponse($content, $tokensIn, $tokensOut, $finish);
    }

    /** @return array<string, string> */
    protected function headers(): array
    {
        return ['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version];
    }

    private function url(): string
    {
        return rtrim($this->baseUrl, '/').'/messages';
    }

    /** @return array<string, mixed> */
    private function payload(LlmRequest $request): array
    {
        return [
            'model' => $this->model($request),
            'max_tokens' => $this->maxTokens($request),
            'system' => $request->system,
            'messages' => $request->messages,
        ];
    }
}
