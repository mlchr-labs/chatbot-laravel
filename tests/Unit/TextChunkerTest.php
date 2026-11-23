<?php

namespace Tests\Unit;

use App\Support\TextChunker;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    public function test_empty_and_whitespace_only_text_yield_no_chunks(): void
    {
        $chunker = new TextChunker;

        $this->assertSame([], $chunker->chunk(''));
        $this->assertSame([], $chunker->chunk("  \n\n \t \n"));
    }

    public function test_short_text_is_a_single_trimmed_chunk(): void
    {
        $this->assertSame(['Olá, mundo.'], (new TextChunker)->chunk("  Olá, mundo.  \n"));
    }

    public function test_chunks_never_exceed_the_target_and_cover_all_words(): void
    {
        $sentences = [];
        for ($i = 1; $i <= 200; $i++) {
            $sentences[] = "A frase número {$i} fala sobre ação, coração e informação.";
        }
        $text = implode(' ', $sentences);

        $chunks = (new TextChunker(300, 50))->chunk($text);

        $this->assertGreaterThan(5, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(300, mb_strlen($chunk));
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
        }

        $joined = implode(' ', $chunks);
        for ($i = 1; $i <= 200; $i++) {
            $this->assertStringContainsString("número {$i} fala", $joined);
        }
    }

    public function test_prefers_paragraph_boundaries(): void
    {
        $a = str_repeat('alfa ', 60); // 300 chars
        $b = str_repeat('beta ', 60);
        $c = str_repeat('gama ', 60);

        $chunks = (new TextChunker(700, 0))->chunk(trim($a)."\n\n".trim($b)."\n\n".trim($c));

        $this->assertCount(2, $chunks);
        $this->assertSame(trim($a)."\n\n".trim($b), $chunks[0]);
        $this->assertSame(trim($c), $chunks[1]);
    }

    public function test_splits_a_long_paragraph_at_sentence_boundaries(): void
    {
        $text = str_repeat('Esta é uma frase de tamanho razoável para o teste. ', 12);

        $chunks = (new TextChunker(200, 0))->chunk($text);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, mb_strlen($chunk));
            $this->assertStringEndsWith('teste.', $chunk);
            $this->assertStringStartsWith('Esta', $chunk);
        }
    }

    public function test_consecutive_chunks_overlap_by_at_most_the_configured_size(): void
    {
        $words = [];
        for ($i = 0; $i < 400; $i++) {
            $words[] = 'palavra'.$i;
        }

        $chunks = (new TextChunker(300, 80))->chunk(implode(' ', $words));

        $this->assertGreaterThan(3, count($chunks));

        for ($i = 0; $i < count($chunks) - 1; $i++) {
            $overlap = $this->longestSuffixPrefix($chunks[$i], $chunks[$i + 1]);

            $this->assertGreaterThanOrEqual(20, mb_strlen($overlap), "chunk {$i} has no overlap with the next");
            $this->assertLessThanOrEqual(80, mb_strlen($overlap));
            // Overlap starts on a word boundary, never mid word.
            $this->assertStringStartsWith('palavra', $overlap);
        }
    }

    public function test_zero_overlap_repeats_nothing(): void
    {
        $words = [];
        for ($i = 0; $i < 200; $i++) {
            $words[] = 'w'.$i;
        }

        $chunks = (new TextChunker(100, 0))->chunk(implode(' ', $words));

        $this->assertSame(implode(' ', $words), implode(' ', $chunks));
    }

    public function test_multibyte_text_is_measured_in_characters_and_never_corrupted(): void
    {
        $text = str_repeat('çãõéüñ日本語のテキスト', 200); // no spaces, all multibyte

        $chunks = (new TextChunker(100, 20))->chunk($text);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
            $this->assertLessThanOrEqual(100, mb_strlen($chunk));
            $this->assertNotSame('', $chunk);
        }
    }

    public function test_emoji_are_not_split_in_the_middle_of_a_sequence(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"; // 5 code points, one grapheme
        $text = str_repeat($family, 100);

        $chunks = (new TextChunker(40, 10))->chunk($text);

        foreach ($chunks as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
            preg_match_all('/\X/u', $chunk, $graphemes);
            foreach ($graphemes[0] as $grapheme) {
                $this->assertSame($family, $grapheme);
            }
        }
    }

    public function test_long_word_without_spaces_is_hard_split_with_overlap(): void
    {
        $word = '';
        for ($i = 0; $i < 32; $i++) {
            $word .= md5((string) $i); // 1024 chars, no repeated runs
        }

        $chunks = (new TextChunker(200, 40))->chunk($word);

        $this->assertGreaterThan(4, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, mb_strlen($chunk));
        }

        for ($i = 0; $i < count($chunks) - 1; $i++) {
            $this->assertGreaterThanOrEqual(20, mb_strlen($this->longestSuffixPrefix($chunks[$i], $chunks[$i + 1])));
        }

        // Dropping the overlaps rebuilds the original word.
        $rebuilt = $chunks[0];
        for ($i = 1; $i < count($chunks); $i++) {
            $rebuilt .= mb_substr($chunks[$i], mb_strlen($this->longestSuffixPrefix($chunks[$i - 1], $chunks[$i])));
        }
        $this->assertSame($word, $rebuilt);
    }

    public function test_invalid_utf8_is_scrubbed_instead_of_breaking(): void
    {
        $chunks = (new TextChunker)->chunk("texto v\xE1lido? n\xE3o");

        $this->assertCount(1, $chunks);
        $this->assertTrue(mb_check_encoding($chunks[0], 'UTF-8'));
    }

    public function test_windows_line_endings_are_treated_as_paragraphs(): void
    {
        $chunks = (new TextChunker(50, 0))->chunk("primeiro paragrafo aqui\r\n\r\nsegundo paragrafo aqui\r\n\r\nterceiro paragrafo aqui");

        $this->assertSame(["primeiro paragrafo aqui\n\nsegundo paragrafo aqui", 'terceiro paragrafo aqui'], $chunks);
    }

    public function test_rejects_invalid_configuration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TextChunker(100, 100);
    }

    private function longestSuffixPrefix(string $left, string $right): string
    {
        $max = min(mb_strlen($left), mb_strlen($right));

        for ($length = $max; $length > 0; $length--) {
            $suffix = mb_substr($left, -$length);
            if (str_starts_with($right, $suffix)) {
                return $suffix;
            }
        }

        return '';
    }
}
