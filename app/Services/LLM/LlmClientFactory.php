<?php

namespace App\Services\LLM;

use App\Enums\LlmProvider;
use App\Models\Chatbot;
use App\Services\LLM\Contracts\LlmClient;
use App\Services\LLM\Exceptions\MissingApiKeyException;
use App\Services\LLM\Exceptions\ModelNotAllowedException;
use App\Services\LLM\Providers\AnthropicClient;
use App\Services\LLM\Providers\GeminiClient;
use App\Services\LLM\Providers\OpenAiCompatibleClient;
use Illuminate\Contracts\Config\Repository;

class LlmClientFactory
{
    public function __construct(
        private readonly Repository $config,
        private readonly PlatformModels $platformModels,
    ) {}

    /** @throws MissingApiKeyException when neither the chatbot nor the platform has a key, or the platform key is not allowed for the model */
    public function for(Chatbot $chatbot): LlmClient
    {
        $provider = $chatbot->provider;
        $settings = (array) $this->config->get("llm.providers.{$provider->value}", []);

        // Bring your own key wins over the platform key.
        $apiKey = $chatbot->hasApiKey() ? (string) $chatbot->api_key : (string) ($settings['api_key'] ?? '');

        if ($apiKey === '') {
            throw MissingApiKeyException::forProvider($provider->label());
        }

        // The platform key only pays for allowlisted models. Checked here too, not just on save.
        if (! $chatbot->hasApiKey()
            && filled($chatbot->model)
            && ! $this->platformModels->isAllowed($provider, (string) $chatbot->model)) {
            throw ModelNotAllowedException::forModel($provider->label(), (string) $chatbot->model);
        }

        $common = [
            'apiKey' => $apiKey,
            'baseUrl' => (string) $settings['base_url'],
            'defaultModel' => (string) $settings['model'],
            'timeout' => (int) $this->config->get('llm.timeout', 60),
            'connectTimeout' => (int) $this->config->get('llm.connect_timeout', 5),
            'defaultMaxTokens' => (int) $this->config->get('llm.max_tokens', 1024),
        ];

        return match ($provider) {
            LlmProvider::Anthropic => new AnthropicClient(...$common, version: (string) ($settings['version'] ?? '2026-10-21')),
            LlmProvider::Gemini => new GeminiClient(...$common),
            LlmProvider::OpenAi, LlmProvider::Groq, LlmProvider::DeepSeek => new OpenAiCompatibleClient(
                ...$common,
                maxTokensParam: (string) ($settings['max_tokens_param'] ?? 'max_tokens'),
                sendTemperature: (bool) ($settings['send_temperature'] ?? true),
                streamUsage: (bool) ($settings['stream_usage'] ?? true),
            ),
        };
    }
}
