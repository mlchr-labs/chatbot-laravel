<?php

namespace App\Services\LLM\Exceptions;

class MissingApiKeyException extends LlmException
{
    public static function forProvider(string $provider): self
    {
        return new self("Nenhuma chave de API configurada para o provedor {$provider}.");
    }
}
