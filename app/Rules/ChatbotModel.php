<?php

namespace App\Rules;

use App\Enums\LlmProvider;
use App\Services\LLM\PlatformModels;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * With the platform key the model must be in the per-provider allowlist.
 * With the chatbot's own key it stays free-form, but limited to a safe charset and length.
 */
class ChatbotModel implements ValidationRule
{
    public const BYOK_PATTERN = '/^[A-Za-z0-9._:\/-]+\z/';

    public const MAX_LENGTH = 100;

    public function __construct(
        private readonly ?string $provider,
        private readonly bool $usesOwnKey,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            $fail('O modelo é inválido.');

            return;
        }

        if ($this->usesOwnKey) {
            if (! preg_match(self::BYOK_PATTERN, $value)) {
                $fail('O modelo só pode ter letras, números e os caracteres . _ : / -');
            }

            return;
        }

        $provider = $this->provider !== null ? LlmProvider::tryFrom($this->provider) : null;

        // An invalid provider is reported by its own rule.
        if ($provider === null) {
            return;
        }

        $platform = app(PlatformModels::class);

        if (! $platform->isAllowed($provider, $value)) {
            $fail('Sem uma chave de API própria, só é possível usar: '.implode(', ', $platform->allowed($provider)).'.');
        }
    }
}
