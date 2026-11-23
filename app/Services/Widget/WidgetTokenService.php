<?php

namespace App\Services\Widget;

use Carbon\CarbonImmutable;

/**
 * Stateless token: base64url(json payload) "." base64url(HMAC-SHA256 of the first part).
 * The signing key is derived from the app key with a context string, so it is not the app key itself.
 */
class WidgetTokenService
{
    private const CONTEXT = 'widget-token';

    private const MAX_LENGTH = 1024;

    private readonly string $key;

    public function __construct(
        string $appKey,
        private readonly int $ttlMinutes,
    ) {
        $this->key = hash_hmac('sha256', self::CONTEXT, $appKey, true);
    }

    public function issue(string $conversationUlid, string $chatbotUlid): WidgetToken
    {
        $expiresAt = CarbonImmutable::now()->addMinutes($this->ttlMinutes);

        $body = self::encode(json_encode([
            'c' => $conversationUlid,
            'b' => $chatbotUlid,
            'exp' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return new WidgetToken($body.'.'.$this->sign($body), $expiresAt);
    }

    /** Returns null for anything malformed, tampered with or expired. */
    public function verify(?string $token): ?WidgetTokenPayload
    {
        if ($token === null || $token === '' || strlen($token) > self::MAX_LENGTH) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$body, $signature] = $parts;

        if (! hash_equals($this->sign($body), $signature)) {
            return null;
        }

        $json = self::decode($body);
        $data = $json === null ? null : json_decode($json, true);

        if (! is_array($data)
            || ! is_string($data['c'] ?? null)
            || ! is_string($data['b'] ?? null)
            || ! is_int($data['exp'] ?? null)) {
            return null;
        }

        if ($data['exp'] < CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        return new WidgetTokenPayload($data['c'], $data['b'], $data['exp']);
    }

    private function sign(string $body): string
    {
        return self::encode(hash_hmac('sha256', $body, $this->key, true));
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
