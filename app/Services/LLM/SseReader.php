<?php

namespace App\Services\LLM;

use Generator;
use Psr\Http\Message\StreamInterface;

/** Incremental Server-Sent Events parser: yields each event as soon as its blank line arrives. */
final class SseReader
{
    /**
     * @return Generator<int, array{event: string|null, data: string}>
     */
    public static function events(StreamInterface $body): Generator
    {
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(1024);

            if ($chunk === '') {
                continue;
            }

            $buffer = str_replace("\r\n", "\n", $buffer.$chunk);

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = self::parse(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 2);

                if ($event !== null) {
                    yield $event;
                }
            }
        }

        $event = self::parse(trim(str_replace("\r\n", "\n", $buffer), "\n"));

        if ($event !== null) {
            yield $event;
        }
    }

    /**
     * @return array{event: string|null, data: string}|null
     */
    private static function parse(string $block): ?array
    {
        $event = null;
        $data = [];

        foreach (explode("\n", $block) as $line) {
            if ($line === '' || $line[0] === ':') {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = ltrim($value, ' ');

            if ($field === 'event') {
                $event = $value;
            } elseif ($field === 'data') {
                $data[] = $value;
            }
        }

        return $data === [] ? null : ['event' => $event, 'data' => implode("\n", $data)];
    }
}
