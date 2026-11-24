<?php

namespace Tests\Feature\Llm;

use App\Enums\LlmProvider;
use App\Services\LLM\Catalogs\AnthropicModelCatalog;
use App\Services\LLM\Catalogs\GeminiModelCatalog;
use App\Services\LLM\Catalogs\OpenAiCompatibleModelCatalog;
use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\ModelCatalogFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class ModelCatalogTest extends TestCase
{
    private const KEY = 'secret-key-123';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function catalog(LlmProvider $provider)
    {
        return app(ModelCatalogFactory::class)->for($provider, self::KEY);
    }

    public function test_factory_picks_the_implementation_per_provider(): void
    {
        foreach ([LlmProvider::OpenAi, LlmProvider::Groq, LlmProvider::DeepSeek] as $p) {
            $this->assertInstanceOf(OpenAiCompatibleModelCatalog::class, $this->catalog($p));
        }
        $this->assertInstanceOf(AnthropicModelCatalog::class, $this->catalog(LlmProvider::Anthropic));
        $this->assertInstanceOf(GeminiModelCatalog::class, $this->catalog(LlmProvider::Gemini));
    }

    /** @return array<string, array{LlmProvider, string}> */
    public static function openAiCompatible(): array
    {
        return [
            'openai' => [LlmProvider::OpenAi, 'https://api.openai.com/v1/models'],
            'groq' => [LlmProvider::Groq, 'https://api.groq.com/openai/v1/models'],
            'deepseek' => [LlmProvider::DeepSeek, 'https://api.deepseek.com/models'],
        ];
    }

    #[DataProvider('openAiCompatible')]
    public function test_openai_compatible_lists_models_with_bearer_token(LlmProvider $provider, string $url): void
    {
        Http::fake([$url => Http::response(['object' => 'list', 'data' => [
            ['id' => 'model-a', 'object' => 'model'],
            ['id' => 'model-b', 'object' => 'model'],
            ['id' => 'model-a', 'object' => 'model'],
            ['id' => 'bad id with spaces'],
            ['object' => 'model'],
            'garbage',
        ]])]);

        $this->assertSame(['model-a', 'model-b'], $this->catalog($provider)->models());

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === $url
            && $r->header('Authorization') === ['Bearer '.self::KEY]);
    }

    public function test_openai_compatible_rejects_a_body_without_data(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['unexpected' => true])]);

        $this->expectException(LlmException::class);
        $this->catalog(LlmProvider::Groq)->models();
    }

    public function test_anthropic_follows_pagination_and_sends_required_headers(): void
    {
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['data' => [['type' => 'model', 'id' => 'claude-a'], ['type' => 'model', 'id' => 'claude-b']], 'has_more' => true, 'last_id' => 'claude-b'])
            ->push(['data' => [['type' => 'model', 'id' => 'claude-c']], 'has_more' => false, 'last_id' => 'claude-c']);

        $this->assertSame(['claude-a', 'claude-b', 'claude-c'], $this->catalog(LlmProvider::Anthropic)->models());

        $seen = [];
        Http::assertSentCount(2);
        Http::assertSent(function (Request $r) use (&$seen) {
            $seen[] = $r->url();

            return $r->header('x-api-key') === [self::KEY]
                && $r->header('anthropic-version') === [config('llm.providers.anthropic.version')]
                && ! str_contains($r->url(), self::KEY);
        });

        $this->assertStringStartsWith('https://api.anthropic.com/v1/models?', $seen[0]);
        $this->assertStringContainsString('limit=1000', $seen[0]);
        $this->assertStringNotContainsString('after_id', $seen[0]);
        $this->assertStringContainsString('after_id=claude-b', $seen[1]);
    }

    public function test_anthropic_stops_when_the_provider_never_ends_the_pagination(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => [['id' => 'claude-a']], 'has_more' => true, 'last_id' => 'claude-a'])]);

        $this->assertSame(['claude-a'], $this->catalog(LlmProvider::Anthropic)->models());
        Http::assertSentCount(10);
    }

    public function test_gemini_filters_by_generate_content_strips_prefix_and_paginates(): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['models' => [
                ['name' => 'models/gemini-a', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
                ['name' => 'models/embed-a', 'supportedGenerationMethods' => ['embedContent']],
            ], 'nextPageToken' => 'tok2'])
            ->push(['models' => [
                ['name' => 'models/gemini-b', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-c'],
            ]]);

        $this->assertSame(['gemini-a', 'gemini-b', 'gemini-c'], $this->catalog(LlmProvider::Gemini)->models());

        $urls = [];
        Http::assertSent(function (Request $r) use (&$urls) {
            $urls[] = $r->url();

            return $r->header('x-goog-api-key') === [self::KEY] && ! str_contains($r->url(), self::KEY);
        });

        $this->assertStringContainsString('pageSize=1000', $urls[0]);
        $this->assertStringNotContainsString('pageToken', $urls[0]);
        $this->assertStringContainsString('pageToken=tok2', $urls[1]);
    }

    #[DataProvider('failures')]
    public function test_http_errors_become_safe_exceptions(int $status, string $message): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'leaked '.self::KEY]], $status)]);

        try {
            $this->catalog(LlmProvider::OpenAi)->models();
            $this->fail('Expected an LlmException.');
        } catch (LlmException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($status, $e->getCode());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    /** @return array<string, array{int, string}> */
    public static function failures(): array
    {
        return [
            'invalid key' => [401, 'O provedor de IA recusou a chave de API.'],
            'rate limited' => [429, 'O limite de requisições do provedor de IA foi atingido.'],
            'provider down' => [503, 'O provedor de IA está indisponível no momento.'],
        ];
    }

    public function test_connection_failures_become_an_unreachable_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Não foi possível conectar ao provedor de IA.');
        $this->catalog(LlmProvider::Gemini)->models();
    }

    public function test_timeouts_come_from_the_llm_config(): void
    {
        config(['llm.timeout' => 7, 'llm.connect_timeout' => 3]);

        $catalog = $this->catalog(LlmProvider::Groq);

        $this->assertSame(7, (new ReflectionProperty($catalog, 'timeout'))->getValue($catalog));
        $this->assertSame(3, (new ReflectionProperty($catalog, 'connectTimeout'))->getValue($catalog));
    }
}
