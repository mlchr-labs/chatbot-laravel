<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Splits text into overlapping chunks without ever cutting a multibyte character.
 * All lengths are in characters (code points). Boundaries are preferred in this
 * order: paragraph, sentence, whitespace, and only then a hard cut inside a word.
 */
class TextChunker
{
    /** Below this much room an overlap is not worth carrying into the next chunk. */
    private const MIN_USEFUL_OVERLAP = 20;

    public function __construct(
        private readonly int $targetSize = 800,
        private readonly int $overlap = 120,
    ) {
        if ($targetSize < 1) {
            throw new InvalidArgumentException('Chunk size must be positive.');
        }

        if ($overlap < 0 || $overlap >= $targetSize) {
            throw new InvalidArgumentException('Overlap must be between 0 and the chunk size (exclusive).');
        }
    }

    /**
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8')));

        if ($text === '') {
            return [];
        }

        $chunks = [];
        $buffer = '';
        $bufferLength = 0;
        $hasNewContent = false;

        foreach ($this->atoms($text) as [$separator, $atom]) {
            $atomLength = mb_strlen($atom);
            $separatorLength = strlen($separator);

            if ($buffer !== '' && $bufferLength + $separatorLength + $atomLength > $this->targetSize) {
                $chunks[] = $buffer;

                $room = $this->targetSize - $separatorLength - $atomLength;
                $tail = $this->tail($buffer, min($this->overlap, $room));

                $buffer = $tail;
                $bufferLength = mb_strlen($tail);
                $hasNewContent = false;
            }

            if ($buffer === '') {
                $separator = '';
                $separatorLength = 0;
            }

            $buffer .= $separator.$atom;
            $bufferLength += $separatorLength + $atomLength;
            $hasNewContent = true;
        }

        if ($hasNewContent) {
            $chunks[] = $buffer;
        }

        return array_values(array_filter(
            array_map('trim', $chunks),
            fn (string $chunk) => $chunk !== '',
        ));
    }

    /**
     * Flat list of pieces no longer than the target size, each with the separator
     * that precedes it. Paragraphs stay whole when they fit.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function atoms(string $text): array
    {
        $atoms = [];
        $paragraphs = preg_split('/\n[^\S\n]*\n\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            $separator = "\n\n";

            if (mb_strlen($paragraph) <= $this->targetSize) {
                $atoms[] = [$separator, $paragraph];

                continue;
            }

            $sentences = preg_split('/(?<=[.!?…])\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [$paragraph];

            foreach ($sentences as $sentence) {
                if (mb_strlen($sentence) <= $this->targetSize) {
                    $atoms[] = [$separator, $sentence];
                    $separator = ' ';

                    continue;
                }

                foreach (preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    foreach ($this->splitLongWord($word) as $piece) {
                        $atoms[] = [$separator, $piece];
                        $separator = $piece === $word ? ' ' : '';
                    }

                    // The next word is always space separated, even after a hard split.
                    $separator = ' ';
                }
            }
        }

        if ($atoms !== []) {
            $atoms[0][0] = '';
        }

        return $atoms;
    }

    /**
     * Words longer than the target are cut on grapheme boundaries, in pieces small
     * enough to still fit an overlap tail next to them.
     *
     * @return list<string>
     */
    private function splitLongWord(string $word): array
    {
        if (mb_strlen($word) <= $this->targetSize) {
            return [$word];
        }

        $pieceLength = max(1, $this->targetSize - $this->overlap);
        preg_match_all('/\X/u', $word, $matches);

        $pieces = [];
        $piece = '';
        $pieceChars = 0;

        foreach ($matches[0] as $grapheme) {
            $length = mb_strlen($grapheme);

            if ($piece !== '' && $pieceChars + $length > $pieceLength) {
                $pieces[] = $piece;
                $piece = '';
                $pieceChars = 0;
            }

            $piece .= $grapheme;
            $pieceChars += $length;
        }

        if ($piece !== '') {
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /**
     * The end of a finished chunk, to be repeated at the start of the next one.
     * Starts at a word boundary when the tail contains one.
     */
    private function tail(string $chunk, int $maxLength): string
    {
        if ($maxLength < self::MIN_USEFUL_OVERLAP || mb_strlen($chunk) <= $maxLength) {
            return '';
        }

        $tail = mb_substr($chunk, -$maxLength);
        $before = mb_substr($chunk, -$maxLength - 1, 1);

        if (preg_match('/^\s$/u', $before) === 1) {
            return ltrim($tail);
        }

        if (preg_match('/\s+/u', $tail, $match, PREG_OFFSET_CAPTURE) === 1) {
            return substr($tail, $match[0][1] + strlen($match[0][0]));
        }

        // A single unbroken run (long word): the cut has to land inside it.
        return $tail;
    }
}
