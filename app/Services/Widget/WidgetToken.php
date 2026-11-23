<?php

namespace App\Services\Widget;

use Carbon\CarbonImmutable;

final readonly class WidgetToken
{
    public function __construct(
        public string $value,
        public CarbonImmutable $expiresAt,
    ) {}
}
