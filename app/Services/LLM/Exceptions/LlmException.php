<?php

namespace App\Services\LLM\Exceptions;

use RuntimeException;

/** The message is safe to show or log: it never carries provider bodies or credentials. */
class LlmException extends RuntimeException
{
    public static function fromStatus(int $status): self
    {
        $message = match (true) {
            $status === 401 || $status === 403 => 'O provedor de IA recusou a chave de API.',
            $status === 429 => 'O limite de requisições do provedor de IA foi atingido.',
            $status >= 500 => 'O provedor de IA está indisponível no momento.',
            default => "O provedor de IA recusou a requisição (HTTP {$status}).",
        };

        return new self($message, $status);
    }

    public static function unreachable(): self
    {
        return new self('Não foi possível conectar ao provedor de IA.');
    }

    public static function invalidResponse(): self
    {
        return new self('O provedor de IA devolveu uma resposta inválida.');
    }
}
