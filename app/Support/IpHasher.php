<?php

namespace App\Support;

/** Raw IPs are never stored: only a keyed hash, which is still usable as a rate limit key. */
final class IpHasher
{
    public static function hash(?string $ip): string
    {
        return hash_hmac('sha256', $ip ?? 'unknown', (string) config('app.key'));
    }
}
