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

    /** Default model of the provider (llm.providers.*.model), the one the form pre-selects. */
    public function defaultFor(LlmProvider $provider): string
    {
        return (string) $this->config->get("llm.providers.{$provider->value}.model", '');
    }

    /**
     * What the chatbot form needs to follow the chosen provider without extra requests.
     *
     * @return array<string, array{default: string, models: list<string>}>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach (LlmProvider::cases() as $provider) {
            $catalog[$provider->value] = [
                'default' => $this->defaultFor($provider),
                'models' => $this->allowed($provider),
            ];
        }

        return $catalog;
    }
}
