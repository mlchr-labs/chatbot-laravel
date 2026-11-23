<?php

namespace App\Enums;

use Illuminate\Http\UploadedFile;

/**
 * Accepted knowledge file types. The stored extension and mime always come from
 * here, never from the client supplied file name or Content-Type.
 */
enum KnowledgeFileType: string
{
    case Pdf = 'pdf';
    case Txt = 'txt';
    case Md = 'md';
    case Html = 'html';

    public function mime(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Txt => 'text/plain',
            self::Md => 'text/markdown',
            self::Html => 'text/html',
        };
    }

    /**
     * Content types finfo may report for a genuine file of this type.
     *
     * @return list<string>
     */
    public function acceptedContentTypes(): array
    {
        return match ($this) {
            self::Pdf => ['application/pdf'],
            self::Txt => ['text/plain'],
            self::Md => ['text/plain', 'text/markdown', 'text/x-markdown'],
            self::Html => ['text/html', 'application/xhtml+xml', 'text/plain'],
        };
    }

    /**
     * Matches the declared extension against the real content type sniffed with finfo.
     * Returns null when the content does not look like what the extension claims.
     */
    public static function detect(UploadedFile $file): ?self
    {
        $declared = self::tryFrom(strtolower($file->getClientOriginalExtension()));

        if ($declared === null || $file->getRealPath() === false) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $sniffed = $finfo->file($file->getRealPath());

        if ($sniffed === false) {
            return null;
        }

        return in_array(strtolower($sniffed), $declared->acceptedContentTypes(), true) ? $declared : null;
    }

    public static function fromPath(string $path): ?self
    {
        return self::tryFrom(strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
