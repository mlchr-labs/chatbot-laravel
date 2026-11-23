<?php

namespace App\Services\Widget;

use Closure;

/**
 * Coalesces LLM deltas so the websocket server is not hit once per token:
 * flushes when enough characters are pending or enough time passed since the last flush.
 */
class TokenBuffer
{
    private string $pending = '';

    private float $lastFlush;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param  callable(string): void  $emit
     * @param  (callable(): float)|null  $clock  milliseconds, monotonic
     */
    public function __construct(
        private readonly mixed $emit,
        private readonly int $flushChars = 20,
        private readonly int $flushMs = 40,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : fn (): float => hrtime(true) / 1_000_000;
        $this->lastFlush = ($this->clock)();
    }

    public function add(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->pending .= $delta;

        if (mb_strlen($this->pending) >= $this->flushChars
            || ($this->clock)() - $this->lastFlush >= $this->flushMs) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->lastFlush = ($this->clock)();

        if ($this->pending === '') {
            return;
        }

        $chunk = $this->pending;
        $this->pending = '';

        ($this->emit)($chunk);
    }
}
