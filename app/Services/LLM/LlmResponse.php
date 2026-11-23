<?php

namespace App\Services\LLM;

final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public ?string $finishReason = null,
    ) {}
}
