<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A processing failure whose message is safe to show to the document owner.
 */
class KnowledgeProcessingException extends RuntimeException {}
