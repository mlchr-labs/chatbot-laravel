<?php

namespace App\Services\Widget;

use App\Models\Chatbot;
use App\Support\HostnameNormalizer;
use Illuminate\Http\Request;

class OriginValidator
{
    /**
     * Normalized "host" (or "host:port" when the port is not the scheme default) of the Origin header,
     * or null when absent or not a plain http(s) origin.
     */
    public function hostOf(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if (! is_string($origin) || $origin === '' || strlen($origin) > 300) {
            return null;
        }

        $parts = parse_url($origin);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = HostnameNormalizer::normalize($parts['host']);
        $port = $parts['port'] ?? null;
        $isDefault = $port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $isDefault ? $host : $host.':'.$port;
    }

    /** An empty allowed_domains list denies everything. */
    public function allows(Request $request, Chatbot $chatbot): bool
    {
        $host = $this->hostOf($request);
        if ($host === null) {
            return false;
        }

        $allowed = HostnameNormalizer::parseList($chatbot->allowed_domains ?? []);

        return in_array($host, $allowed, true);
    }
}
