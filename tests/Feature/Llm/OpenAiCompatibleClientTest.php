<?php

namespace Tests\Feature\Llm;

use App\Enums\LlmProvider;
use App\Models\Chatbot;
use App\Services\LLM\Contracts\LlmClient;
use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\LlmClientFactory;
use App\Services\LLM\LlmRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiCompatibleClientTest extends TestCase
{
    private const KEY = 'sk-super-secret-1234';

    private function sse(): string
    {
        return implode("\n\n", [
            'data: {"choices":[{"delta":{"role":"assistant","content":""},"finish_reason":null}]}',
            'data: {"choices":[{"delta":{"content":"Olá"},"finish_reason":null}]}',
            'data: {"choices":[{"delta":{"content":", mundo"},"finish_reason":null}]}',
            'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}',
            'data: {"choices":[],"usage":{"prompt_tokens":21,"completion_tokens":5,"total_tokens":26}}',
            'data: [DONE]',
        ])."\n\n";
    }

    private function request(): LlmRequest
    {
        return new LlmRequest(null, 'Sistema', [['role' => 'user', 'content' => 'Oi']], 0.4, 300);
    }

    private function client(LlmProvider $provider = LlmProvider::OpenAi): LlmClient
    {
        return app(LlmClientFactory::class)->for(
            (new Chatbot)->forceFill(['provider' => $provider, 'api_key' => self::KEY])
        );
    }

    public function test_stream_emits_tokens_in_order_and_parses_usage(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->sse(), 200, ['Content-Type' => 'text/event-stream'])]);

        $tokens = [];
        $response = $this->client()->stream($this->request(), function (string $t) use (&$tokens) {
            $tokens[] = $t;
        });

        $this->assertSame(['Olá', ', mundo'], $tokens);
        $this->assertSame('Olá, mundo', $response->content);
        $this->assertSame(21, $response->tokensIn);
        $this->assertSame(5, $response->tokensOut);
        $this->assertSame('stop', $response->finishReason);

        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://api.openai.com/v1/chat/completions'
                && $r->header('Authorization') === ['Bearer '.self::KEY]
                && $r['stream'] === true
                && $r['stream_options'] === ['include_usage' => true]
                && $r['model'] === config('llm.providers.openai.model')
                && $r['max_completion_tokens'] === 300
                && ! isset($r['temperature'])
                && $r['messages'][0] === ['role' => 'system', 'content' => 'Sistema']
                && $r['messages'][1] === ['role' => 'user', 'content' => 'Oi'];
        });
    }

    public function test_groq_uses_its_base_url_max_tokens_and_reads_x_groq_usage(): void
    {
        $body = implode("\n\n", [
            'data: {"choices":[{"delta":{"content":"Oi"}}]}',
            'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"x_groq":{"usage":{"prompt_tokens":7,"completion_tokens":2}}}',
            'data: [DONE]',
        ])."\n\n";
        Http::fake(['api.groq.com/*' => Http::response($body)]);

        $response = $this->client(LlmProvider::Groq)->stream($this->request(), fn () => null);

        $this->assertSame([7, 2], [$response->tokensIn, $response->tokensOut]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $r['max_tokens'] === 300
            && $r['temperature'] === 0.4
            && ! isset($r['stream_options']));
    }

    public function test_deepseek_hits_its_own_base_url(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response($this->sse())]);

        $this->client(LlmProvider::DeepSeek)->stream($this->request(), fn () => null);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.deepseek.com/chat/completions');
    }

    public function test_complete_parses_a_regular_response(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Resposta'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4],
        ])]);

        $response = $this->client()->complete($this->request());

        $this->assertSame(['Resposta', 3, 4, 'stop'], [$response->content, $response->tokensIn, $response->tokensOut, $response->finishReason]);
        Http::assertSent(fn (Request $r) => ! isset($r['stream']));
    }

    public function test_http_errors_map_to_safe_exceptions_without_leaking_key_or_body(): void
    {
        $expected = [401 => 'recusou a chave', 429 => 'limite', 503 => 'indisponível'];
        $sequence = Http::sequence();
        foreach (array_keys($expected) as $status) {
            $sequence->push(['error' => ['message' => 'bad key '.self::KEY]], $status);
        }
        Http::fake(['*' => $sequence]);

        foreach ($expected as $status => $needle) {

            try {
                $this->client()->stream($this->request(), fn () => null);
                $this->fail("Expected exception for {$status}");
            } catch (LlmException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
                $this->assertStringNotContainsString(self::KEY, (string) $e->getPrevious()?->getMessage());
                $this->assertSame($status, $e->getCode());
            }
        }
    }

    public function test_connection_failure_becomes_llm_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error with '.self::KEY));

        try {
            $this->client()->complete($this->request());
            $this->fail('Expected exception');
        } catch (LlmException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }
}
