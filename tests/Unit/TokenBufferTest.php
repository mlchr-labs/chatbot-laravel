<?php

namespace Tests\Unit;

use App\Services\Widget\TokenBuffer;
use PHPUnit\Framework\TestCase;

class TokenBufferTest extends TestCase
{
    public function test_flushes_by_size_and_keeps_order(): void
    {
        $out = [];
        $now = 0.0;
        $buffer = new TokenBuffer(function (string $d) use (&$out) {
            $out[] = $d;
        }, 10, 1000, fn () => $now);

        $buffer->add('abcd');
        $buffer->add('efgh');
        $this->assertSame([], $out);

        $buffer->add('ijkl');
        $this->assertSame(['abcdefghijkl'], $out);

        $buffer->add('mn');
        $buffer->flush();
        $this->assertSame(['abcdefghijkl', 'mn'], $out);
        $this->assertSame('abcdefghijklmn', implode('', $out));
    }

    public function test_flushes_by_time(): void
    {
        $out = [];
        $now = 0.0;
        $buffer = new TokenBuffer(function (string $d) use (&$out) {
            $out[] = $d;
        }, 1000, 40, function () use (&$now) {
            return $now;
        });

        $buffer->add('a');
        $now = 41.0;
        $buffer->add('b');

        $this->assertSame(['ab'], $out);
    }

    public function test_counts_multibyte_characters_and_ignores_empty(): void
    {
        $out = [];
        $buffer = new TokenBuffer(function (string $d) use (&$out) {
            $out[] = $d;
        }, 3, 100000);

        $buffer->add('');
        $buffer->add('çãó');
        $buffer->flush();
        $buffer->flush();

        $this->assertSame(['çãó'], $out);
    }
}
