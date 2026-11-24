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

    /**
     * An empty allowed_domains list denies everything, except for the app's own origin
     * while the chatbot has its public sandbox enabled.
     */
    public function allows(Request $request, Chatbot $chatbot): bool
    {
        if ($chatbot->sandbox_enabled && $this->isAppOrigin($request)) {
            return true;
        }

        $host = $this->hostOf($request);
        if ($host === null) {
            return false;
        }

        $allowed = HostnameNormalizer::parseList($chatbot->allowed_domains ?? []);

        return in_array($host, $allowed, true);
    }

    /**
     * Whether the request comes from the app itself (scheme, host and port of APP_URL).
     * Browsers omit Origin on same-origin GET requests, so Sec-Fetch-Site (set by the browser, not by scripts)
     * stands in when the header is absent.
     */
    private function isAppOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if (! is_string($origin) || $origin === '') {
            return $request->headers->get('Sec-Fetch-Site') === 'same-origin';
        }

        $appOrigin = $this->canonicalOrigin((string) config('app.url'));

        return $appOrigin !== null && $this->canonicalOrigin($origin) === $appOrigin;
    }

    /** "scheme://host[:port]" lowercased, without the default port. Null when it is not an http(s) URL. */
    private function canonicalOrigin(string $url): ?string
    {
        if (strlen($url) > 300) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $isDefault = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $scheme.'://'.HostnameNormalizer::normalize($parts['host']).($isDefault ? '' : ':'.$port);
    }
}
