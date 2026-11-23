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

class AnthropicClientTest extends TestCase
{
    private const KEY = 'sk-ant-secret-9999';

    private function client(): LlmClient
    {
        return app(LlmClientFactory::class)->for(
            (new Chatbot)->forceFill(['provider' => LlmProvider::Anthropic, 'api_key' => self::KEY])
        );
    }

    private function request(): LlmRequest
    {
        return new LlmRequest('claude-haiku-5-5', 'Sistema', [['role' => 'user', 'content' => 'Oi']], 0.7);
    }

    private function sse(): string
    {
        return implode("\n\n", [
            "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\",\"role\":\"assistant\",\"content\":[],\"usage\":{\"input_tokens\":25,\"output_tokens\":1}}}",
            "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}",
            "event: ping\ndata: {\"type\":\"ping\"}",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Olá\"}}",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"!\"}}",
            "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}",
            "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\",\"stop_sequence\":null},\"usage\":{\"output_tokens\":15}}",
            "event: message_stop\ndata: {\"type\":\"message_stop\"}",
        ])."\n\n";
    }

    public function test_stream_order_usage_headers_and_payload(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sse(), 200, ['Content-Type' => 'text/event-stream'])]);

        $tokens = [];
        $response = $this->client()->stream($this->request(), function (string $t) use (&$tokens) {
            $tokens[] = $t;
        });

        $this->assertSame(['Olá', '!'], $tokens);
        $this->assertSame('Olá!', $response->content);
        $this->assertSame(25, $response->tokensIn);
        $this->assertSame(15, $response->tokensOut);
        $this->assertSame('end_turn', $response->finishReason);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.anthropic.com/v1/messages'
            && $r->header('x-api-key') === [self::KEY]
            && $r->header('anthropic-version') === ['2026-10-21']
            && $r['stream'] === true
            && $r['model'] === 'claude-haiku-5-5'
            && $r['system'] === 'Sistema'
            && $r['max_tokens'] === 1024
            && $r['messages'] === [['role' => 'user', 'content' => 'Oi']]
            // Current Claude models answer 400 to sampling parameters.
            && ! isset($r['temperature']));
    }

    public function test_default_model_comes_from_config(): void
    {
        Http::fake(['*' => Http::response($this->sse())]);

        $this->client()->stream(new LlmRequest(null, 's', [['role' => 'user', 'content' => 'x']]), fn () => null);

        Http::assertSent(fn (Request $r) => $r['model'] === config('llm.providers.anthropic.model'));
    }

    public function test_complete_joins_text_blocks(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'A'], ['type' => 'text', 'text' => 'B']],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
            'stop_reason' => 'end_turn',
        ])]);

        $r = $this->client()->complete($this->request());

        $this->assertSame(['AB', 2, 3, 'end_turn'], [$r->content, $r->tokensIn, $r->tokensOut, $r->finishReason]);
    }

    public function test_error_event_in_stream_is_mapped_without_provider_body(): void
    {
        $body = "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"a\"}}\n\n"
            ."event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"internal ".self::KEY."\"}}\n\n";
        Http::fake(['*' => Http::response($body)]);

        $this->expectException(LlmException::class);

        try {
            $this->client()->stream($this->request(), fn () => null);
        } catch (LlmException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            throw $e;
        }
    }

    public function test_http_errors_do_not_leak_the_key(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => self::KEY]], 401)]);

        try {
            $this->client()->stream($this->request(), fn () => null);
            $this->fail('Expected exception');
        } catch (LlmException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertSame(401, $e->getCode());
        }
    }
}
