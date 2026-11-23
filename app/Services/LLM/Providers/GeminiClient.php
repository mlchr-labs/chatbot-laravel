<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\LlmRequest;
use App\Services\LLM\LlmResponse;

/** generateContent / streamGenerateContent?alt=sse. The key travels in a header, never in the url. */
class GeminiClient extends HttpLlmClient
{
    public function complete(LlmRequest $request): LlmResponse
    {
        $json = $this->postJson($this->url($request, 'generateContent'), $this->payload($request));

        [$text, $in, $out, $finish] = $this->extract($json);

        return new LlmResponse($text, $in ?? 0, $out ?? 0, $finish);
    }

    public function stream(LlmRequest $request, callable $onToken): LlmResponse
    {
        $content = '';
        $tokensIn = 0;
        $tokensOut = 0;
        $finish = null;

        foreach ($this->postStream($this->url($request, 'streamGenerateContent', '?alt=sse'), $this->payload($request)) as ['json' => $chunk]) {
            [$text, $in, $out, $reason] = $this->extract($chunk);

            if ($text !== '') {
                $content .= $text;
                $onToken($text);
            }

            // usageMetadata is cumulative and may only appear on the last chunks.
            $tokensIn = $in ?? $tokensIn;
            $tokensOut = $out ?? $tokensOut;
            $finish = $reason ?? $finish;
        }

        return new LlmResponse($content, $tokensIn, $tokensOut, $finish);
    }

    /** @return array<string, string> */
    protected function headers(): array
    {
        return ['x-goog-api-key' => $this->apiKey];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{0: string, 1: int|null, 2: int|null, 3: string|null}
     */
    private function extract(array $json): array
    {
        $text = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            // Thought summaries are flagged and not part of the answer.
            if (($part['thought'] ?? false) !== true) {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        $usage = $json['usageMetadata'] ?? null;
        $in = isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null;
        $out = isset($usage['candidatesTokenCount'])
            ? (int) $usage['candidatesTokenCount'] + (int) ($usage['thoughtsTokenCount'] ?? 0)
            : null;

        return [$text, $in, $out, $json['candidates'][0]['finishReason'] ?? null];
    }

    private function url(LlmRequest $request, string $method, string $query = ''): string
    {
        $model = $this->model($request);

        // The model name is user supplied and ends up in the path.
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $model)) {
            throw new LlmException('Nome de modelo inválido.');
        }

        return rtrim($this->baseUrl, '/')."/models/{$model}:{$method}{$query}";
    }

    /** @return array<string, mixed> */
    private function payload(LlmRequest $request): array
    {
        $generationConfig = ['maxOutputTokens' => $this->maxTokens($request)];

        if ($request->temperature !== null) {
            $generationConfig['temperature'] = $request->temperature;
        }

        return [
            'systemInstruction' => ['parts' => [['text' => $request->system]]],
            'contents' => array_map(fn (array $m) => [
                'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ], $request->messages),
            'generationConfig' => $generationConfig,
        ];
    }
}
