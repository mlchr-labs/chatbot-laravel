<?php

namespace App\Services\Widget;

use App\Models\Chatbot;
use App\Support\HostnameNormalizer;
use Illuminate\Http\Request;

class OriginValidator
{
    /** Hosts allowed to use http:// as the Origin scheme. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * Normalized "host" (or "host:port" when the port is not the scheme default) of the Origin header,
     * or null when absent, not an http(s) origin, or plain http on a host that is not localhost/127.0.0.1.
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

        // Plain http is only accepted for local development hosts.
        if ($scheme === 'http' && ! in_array($host, self::LOCAL_HOSTS, true)) {
            return null;
        }

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
