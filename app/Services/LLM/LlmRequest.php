<?php

namespace App\Services\LLM;

final readonly class LlmRequest
{
    /**
     * @param  string|null  $model  null lets the client use the provider default from config/llm.php
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function __construct(
        public ?string $model,
        public string $system,
        public array $messages,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
    ) {}
}
