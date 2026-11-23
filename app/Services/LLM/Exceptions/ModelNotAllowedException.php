<?php

namespace App\Services\LLM\Exceptions;

/** Platform key requested for a model outside the allowlist. Handled like a missing key ("not configured"). */
class ModelNotAllowedException extends MissingApiKeyException
{
    public static function forModel(string $provider, string $model): self
    {
        return new self("O modelo {$model} não é permitido com a chave da plataforma ({$provider}).");
    }
}
