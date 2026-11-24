<?php

namespace App\Exceptions;

use RuntimeException;

/** A retry that cannot happen. The message is written for the owner and is safe to show. */
class KnowledgeRetryException extends RuntimeException
{
    public static function notFailed(): self
    {
        return new self('Só é possível tentar novamente um documento que falhou.');
    }

    public static function notRetryable(): self
    {
        return new self('Este documento não pode ser reprocessado. Envie uma versão corrigida do arquivo.');
    }

    public static function fileMissing(): self
    {
        return new self('O arquivo original não está mais disponível. Exclua o documento e envie o arquivo novamente.');
    }
}
