<?php

namespace App\Services\LLM;

use App\Enums\LlmProvider;
use App\Services\LLM\Exceptions\LlmException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/** Model ids available to an api key, with a short cache. The key never reaches the cache key in clear. */
class ProviderModels
{
    public function __construct(
        private readonly ModelCatalogFactory $catalogs,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    /**
     * @return list<string>
     *
     * @throws LlmException
     */
    public function list(LlmProvider $provider, string $apiKey): array
    {
        $cacheKey = $this->cacheKey($provider, $apiKey);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        // Only successes are cached: a bad key or a timeout must be retryable right away.
        $models = $this->catalogs->for($provider, $apiKey)->models();

        $this->cache->put($cacheKey, $models, (int) $this->config->get('llm.models_cache_ttl', 300));

        return $models;
    }

    private function cacheKey(LlmProvider $provider, string $apiKey): string
    {
        return "llm:models:{$provider->value}:".hash_hmac('sha256', $apiKey, (string) $this->config->get('app.key'));
    }
}
