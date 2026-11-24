<?php

namespace App\Services\LLM\Catalogs;

use App\Services\LLM\Exceptions\LlmException;

/**
 * GET {base_url}/models with the x-goog-api-key header. Token pagination:
 * { models: [{ name: "models/x", supportedGenerationMethods }], nextPageToken }.
 * Only models that support generateContent are returned.
 */
class GeminiModelCatalog extends HttpModelCatalog
{
    public function models(): array
    {
        $ids = [];
        $query = ['pageSize' => 1000];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $json = $this->getJson('/models', $query);

            if (! is_array($json['models'] ?? null)) {
                throw LlmException::invalidResponse();
            }

            foreach ($json['models'] as $item) {
                if (is_array($item) && $this->generatesContent($item)) {
                    $ids[] = $this->id($item);
                }
            }

            $next = $json['nextPageToken'] ?? null;

            if (! is_string($next) || $next === '') {
                break;
            }

            $query['pageToken'] = $next;
        }

        return $this->clean($ids);
    }

    protected function headers(): array
    {
        return ['x-goog-api-key' => $this->apiKey];
    }

    /** @param  array<string, mixed>  $model */
    private function id(array $model): ?string
    {
        $name = $model['name'] ?? $model['baseModelId'] ?? null;

        return is_string($name) ? preg_replace('#^models/#', '', $name) : null;
    }

    /**
     * Documented field is supportedGenerationMethods. The SDKs call it supportedActions, so it is read too.
     * A model that reports neither is kept: dropping everything would be worse than listing an odd one.
     *
     * @param  array<string, mixed>  $model
     */
    private function generatesContent(array $model): bool
    {
        $methods = $model['supportedGenerationMethods'] ?? $model['supportedActions'] ?? null;

        return ! is_array($methods) || in_array('generateContent', $methods, true);
    }
}
