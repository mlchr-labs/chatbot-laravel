<?php

namespace Tests\Unit;

use App\Enums\LlmProvider;
use App\Services\LLM\Contracts\ModelCatalog;
use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\ModelCatalogFactory;
use App\Services\LLM\ProviderModels;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository as Config;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ProviderModelsTest extends TestCase
{
    private const KEY = 'sk-super-secret-key';

    private ArrayStore $store;

    private object $spy;

    private ProviderModels $service;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new Config(['app' => ['key' => 'base64:test'], 'llm' => ['models_cache_ttl' => 60]]);

        $this->spy = new class
        {
            public int $calls = 0;

            public bool $fail = false;

            /** @var list<string> */
            public array $keys = [];
        };
        $spy = $this->spy;

        $factory = new class($config, $spy) extends ModelCatalogFactory
        {
            public function __construct(Config $config, private readonly object $spy)
            {
                parent::__construct($config);
            }

            public function for(LlmProvider $provider, string $apiKey): ModelCatalog
            {
                $this->spy->keys[] = $provider->value.':'.$apiKey;
                $spy = $this->spy;

                return new class($spy) implements ModelCatalog
                {
                    public function __construct(private readonly object $spy) {}

                    public function models(): array
                    {
                        $this->spy->calls++;

                        return $this->spy->fail ? throw LlmException::unreachable() : ['m-1', 'm-2'];
                    }
                };
            }
        };

        $this->store = new ArrayStore;
        $this->service = new ProviderModels($factory, new Cache($this->store), $config);
    }

    public function test_second_call_is_served_from_cache(): void
    {
        $this->assertSame(['m-1', 'm-2'], $this->service->list(LlmProvider::Groq, self::KEY));
        $this->assertSame(['m-1', 'm-2'], $this->service->list(LlmProvider::Groq, self::KEY));

        $this->assertSame(1, $this->spy->calls);
    }

    public function test_cache_is_split_by_provider_and_by_key(): void
    {
        $this->service->list(LlmProvider::Groq, self::KEY);
        $this->service->list(LlmProvider::OpenAi, self::KEY);
        $this->service->list(LlmProvider::Groq, 'another-key');

        $this->assertSame(3, $this->spy->calls);
    }

    public function test_the_api_key_never_appears_in_the_cache_key(): void
    {
        $this->service->list(LlmProvider::Groq, self::KEY);

        $storage = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($this->store);

        $this->assertCount(1, $storage);
        foreach (array_keys($storage) as $key) {
            $this->assertStringNotContainsString(self::KEY, $key);
            $this->assertStringStartsWith('llm:models:groq:', $key);
        }
    }

    public function test_failures_are_not_cached(): void
    {
        $this->spy->fail = true;

        try {
            $this->service->list(LlmProvider::Groq, self::KEY);
            $this->fail('Expected an LlmException.');
        } catch (LlmException) {
        }

        $this->spy->fail = false;

        $this->assertSame(['m-1', 'm-2'], $this->service->list(LlmProvider::Groq, self::KEY));
        $this->assertSame(2, $this->spy->calls);
    }
}
