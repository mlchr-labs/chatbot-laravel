<?php

namespace App\Support;

/**
 * Parses and validates the allowed domains list of a chatbot.
 * An entry is a bare hostname (optionally with port): no scheme, path, query or wildcard.
 */
final class HostnameNormalizer
{
    private const PATTERN = '/^(?=.{1,253}(?::\d{1,5})?$)(?!-)[a-z0-9-]{1,63}(?<!-)(?:\.(?!-)[a-z0-9-]{1,63}(?<!-))*(?::\d{1,5})?$/';

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Accepts an array or a string separated by newlines, commas or spaces.
     * Returns normalized, de-duplicated, non-empty entries (validity is not checked here).
     *
     * @return list<string>
     */
    public static function parseList(array|string|null $input): array
    {
        if ($input === null) {
            return [];
        }

        $items = is_array($input) ? $input : preg_split('/[\s,]+/', $input);

        $normalized = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                $normalized[] = '';

                continue;
            }
            $item = self::normalize($item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }
}
