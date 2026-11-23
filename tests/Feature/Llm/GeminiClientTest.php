<?php

namespace Tests\Feature\Llm;

use App\Enums\LlmProvider;
use App\Models\Chatbot;
use App\Services\LLM\Contracts\LlmClient;
use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\LlmClientFactory;
use App\Services\LLM\LlmRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    private const KEY = 'AIza-secret-key';

    private function client(): LlmClient
    {
        return app(LlmClientFactory::class)->for(
            (new Chatbot)->forceFill(['provider' => LlmProvider::Gemini, 'api_key' => self::KEY])
        );
    }

    private function request(string $model = 'gemini-test'): LlmRequest
    {
        return new LlmRequest($model, 'Sistema', [
            ['role' => 'user', 'content' => 'Oi'],
            ['role' => 'assistant', 'content' => 'Olá'],
            ['role' => 'user', 'content' => 'Tudo bem?'],
        ], 0.5, 200);
    }

    public function test_stream_order_usage_and_request_shape(): void
    {
        $body = implode("\r\n\r\n", [
            'data: {"candidates":[{"content":{"role":"model","parts":[{"text":"Tudo"}]}}],"usageMetadata":{"promptTokenCount":12}}',
            'data: {"candidates":[{"content":{"role":"model","parts":[{"text":" bem"}]}}]}',
            'data: {"candidates":[{"content":{"role":"model","parts":[{"text":"!"}]},"finishReason":"STOP"}],"usageMetadata":{"promptTokenCount":12,"candidatesTokenCount":4,"thoughtsTokenCount":6}}',
        ])."\r\n\r\n";
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

        $tokens = [];
        $response = $this->client()->stream($this->request(), function (string $t) use (&$tokens) {
            $tokens[] = $t;
        });

        $this->assertSame(['Tudo', ' bem', '!'], $tokens);
        $this->assertSame(['Tudo bem!', 12, 10, 'STOP'], [$response->content, $response->tokensIn, $response->tokensOut, $response->finishReason]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:streamGenerateContent?alt=sse'
            && $r->header('x-goog-api-key') === [self::KEY]
            && ! str_contains($r->url(), self::KEY)
            && $r['systemInstruction'] === ['parts' => [['text' => 'Sistema']]]
            && $r['contents'][1] === ['role' => 'model', 'parts' => [['text' => 'Olá']]]
            && $r['contents'][2]['role'] === 'user'
            && $r['generationConfig'] === ['maxOutputTokens' => 200, 'temperature' => 0.5]);
    }

    public function test_complete_uses_generate_content(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Oi']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 2],
        ])]);

        $r = $this->client()->complete($this->request());

        $this->assertSame(['Oi', 1, 2, 'STOP'], [$r->content, $r->tokensIn, $r->tokensOut, $r->finishReason]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/models/gemini-test:generateContent'));
    }

    public function test_model_name_cannot_inject_path_segments(): void
    {
        Http::fake();

        $this->expectException(LlmException::class);

        try {
            $this->client()->complete($this->request('../other:thing?x='));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_http_errors_do_not_leak_the_key(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => self::KEY]], 429)]);

        try {
            $this->client()->stream($this->request(), fn () => null);
            $this->fail('Expected exception');
        } catch (LlmException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertSame(429, $e->getCode());
        }
    }
}
