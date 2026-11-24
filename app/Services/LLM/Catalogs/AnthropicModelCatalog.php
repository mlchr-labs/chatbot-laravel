<?php

namespace App\Services\LLM\Catalogs;

use App\Services\LLM\Exceptions\LlmException;

/**
 * GET {base_url}/models with x-api-key and anthropic-version. Cursor pagination:
 * { data: [{ id }], has_more, last_id }, next page through ?after_id=last_id.
 */
class AnthropicModelCatalog extends HttpModelCatalog
{
    public function __construct(
        string $apiKey,
        string $baseUrl,
        int $timeout = 60,
        int $connectTimeout = 5,
        private readonly string $version = '2026-10-21',
    ) {
        parent::__construct($apiKey, $baseUrl, $timeout, $connectTimeout);
    }

    public function models(): array
    {
        $ids = [];
        $query = ['limit' => 1000];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $json = $this->getJson('/models', $query);

            if (! is_array($json['data'] ?? null)) {
                throw LlmException::invalidResponse();
            }

            foreach ($json['data'] as $item) {
                $ids[] = is_array($item) ? ($item['id'] ?? null) : null;
            }

            $last = $json['last_id'] ?? null;

            if (($json['has_more'] ?? false) !== true || ! is_string($last) || $last === '') {
                break;
            }

            $query['after_id'] = $last;
        }

        return $this->clean($ids);
    }

    protected function headers(): array
    {
        return ['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version];
    }
}
