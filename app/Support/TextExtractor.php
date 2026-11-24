<?php

namespace App\Support;

use App\Enums\KnowledgeFailureReason;
use App\Enums\KnowledgeFileType;
use App\Exceptions\KnowledgeProcessingException;
use Spatie\PdfToText\Pdf;
use Throwable;

class TextExtractor
{
    public function __construct(
        private readonly ?string $pdftotextPath = null,
        private readonly int $pdfMaxPages = 0,
    ) {}

    public function extract(string $absolutePath, KnowledgeFileType $type): string
    {
        $text = match ($type) {
            KnowledgeFileType::Pdf => $this->fromPdf($absolutePath),
            KnowledgeFileType::Html => $this->fromHtml($this->readUtf8($absolutePath)),
            KnowledgeFileType::Txt, KnowledgeFileType::Md => $this->readUtf8($absolutePath),
        };

        return $this->normalize($text);
    }

    public function fromPdf(string $path): string
    {
        try {
            return $this->toUtf8(Pdf::getText($path, $this->pdftotextPath ?: null, $this->pdfOptions()));
        } catch (Throwable $e) {
            report($e);

            throw new KnowledgeProcessingException('Could not read the PDF.', KnowledgeFailureReason::ExtractionFailed, $e);
        }
    }

    /** @return list<string> pdftotext options; "-l N" stops after page N (0 means no limit). */
    private function pdfOptions(): array
    {
        return $this->pdfMaxPages > 0 ? ['-l '.$this->pdfMaxPages] : [];
    }

    public function fromHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|template|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|section|article|header|footer|li|ul|ol|table|tr|h[1-6]|blockquote|pre)\s*>#i', "\n\n", $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Unifies line endings and whitespace but keeps paragraph breaks (blank lines).
     */
    public function normalize(string $text): string
    {
        $text = $this->toUtf8($text);
        $text = str_replace(["\r\n", "\r", "\f", "\v"], ["\n", "\n", "\n\n", "\n"], $text);
        // Control characters (keeps \n and \t), zero width spaces and BOM.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text; // includes nbsp
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function readUtf8(string $path): string
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new KnowledgeProcessingException('Could not read the uploaded file.', KnowledgeFailureReason::ExtractionFailed);
        }

        return $this->toUtf8($raw);
    }

    private function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($raw, "\xFE\xFF")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        // Legacy single byte files (very common for pt-BR .txt exported from Windows).
        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
