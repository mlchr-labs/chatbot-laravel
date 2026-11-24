<?php

namespace App\Services\LLM;

use App\Enums\LlmProvider;
use App\Services\LLM\Catalogs\AnthropicModelCatalog;
use App\Services\LLM\Catalogs\GeminiModelCatalog;
use App\Services\LLM\Catalogs\OpenAiCompatibleModelCatalog;
use App\Services\LLM\Contracts\ModelCatalog;
use Illuminate\Contracts\Config\Repository;

class ModelCatalogFactory
{
    public function __construct(private readonly Repository $config) {}

    public function for(LlmProvider $provider, string $apiKey): ModelCatalog
    {
        $settings = (array) $this->config->get("llm.providers.{$provider->value}", []);

        $common = [
            'apiKey' => $apiKey,
            'baseUrl' => (string) ($settings['base_url'] ?? ''),
            'timeout' => (int) $this->config->get('llm.timeout', 60),
            'connectTimeout' => (int) $this->config->get('llm.connect_timeout', 5),
            'nonChatPatterns' => array_values(array_filter((array) $this->config->get('llm.non_chat_model_patterns', []), is_string(...))),
        ];

        return match ($provider) {
            LlmProvider::Anthropic => new AnthropicModelCatalog(...$common, version: (string) ($settings['version'] ?? '2026-10-21')),
            LlmProvider::Gemini => new GeminiModelCatalog(...$common),
            LlmProvider::OpenAi, LlmProvider::Groq, LlmProvider::DeepSeek => new OpenAiCompatibleModelCatalog(...$common),
        };
    }
}
