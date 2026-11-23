<?php

namespace App\Services\Widget;

final readonly class WidgetTokenPayload
{
    public function __construct(
        public string $conversationUlid,
        public string $chatbotUlid,
        public int $expiresAt,
        public string $keyVersion,
    ) {}
}
