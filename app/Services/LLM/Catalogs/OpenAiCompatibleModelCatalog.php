<?php

namespace App\Services\LLM\Catalogs;

use App\Services\LLM\Exceptions\LlmException;

/** GET {base_url}/models (OpenAI, Groq, DeepSeek): { "object": "list", "data": [{ "id": "..." }] }. Not paginated. */
class OpenAiCompatibleModelCatalog extends HttpModelCatalog
{
    public function models(): array
    {
        $json = $this->getJson('/models');

        if (! is_array($json['data'] ?? null)) {
            throw LlmException::invalidResponse();
        }

        $ids = [];
        foreach ($json['data'] as $item) {
            $ids[] = is_array($item) ? ($item['id'] ?? null) : null;
        }

        return $this->clean($ids);
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiKey];
    }
}
