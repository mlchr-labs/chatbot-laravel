<?php

namespace App\Exceptions;

use App\Enums\KnowledgeFailureReason;
use RuntimeException;
use Throwable;

/**
 * A processing failure the pipeline understands. The message is for logs; the panel only
 * ever shows the text of the reason.
 */
class KnowledgeProcessingException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ?KnowledgeFailureReason $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
