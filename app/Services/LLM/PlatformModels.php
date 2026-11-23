<?php

namespace App\Services\LLM;

use App\Enums\LlmProvider;
use Illuminate\Contracts\Config\Repository;

/** Models a chatbot may use while it runs on the platform key. Configured in llm.platform_models. */
class PlatformModels
{
    public function __construct(private readonly Repository $config) {}

    /** @return list<string> */
    public function allowed(LlmProvider $provider): array
    {
        $models = $this->config->get("llm.platform_models.{$provider->value}");

        if (! is_array($models) || $models === []) {
            $models = [$this->config->get("llm.providers.{$provider->value}.model")];
        }

        return array_values(array_filter(array_map('strval', $models), static fn (string $m): bool => $m !== ''));
    }

    public function isAllowed(LlmProvider $provider, string $model): bool
    {
        return in_array($model, $this->allowed($provider), true);
    }
}
