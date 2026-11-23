<?php

namespace Tests\Unit;

use App\Services\LLM\SseReader;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class SseReaderTest extends TestCase
{
    public function test_events_are_yielded_as_soon_as_their_blank_line_arrives(): void
    {
        $pieces = (function () {
            yield "event: a\ndata: {\"n\":1}\n";
            yield "\ndata: {\"n\"";
            yield ":2}\r\n\r\n: comment\n\ndata: [DONE]\n\n";
        })();

        $events = iterator_to_array(SseReader::events(Utils::streamFor($pieces)), false);

        $this->assertSame([
            ['event' => 'a', 'data' => '{"n":1}'],
            ['event' => null, 'data' => '{"n":2}'],
            ['event' => null, 'data' => '[DONE]'],
        ], $events);
    }

    public function test_last_event_without_trailing_blank_line_is_flushed_and_multiline_data_joined(): void
    {
        $events = iterator_to_array(SseReader::events(Utils::streamFor("data: x\ndata: y")), false);

        $this->assertSame([['event' => null, 'data' => "x\ny"]], $events);
    }
}
