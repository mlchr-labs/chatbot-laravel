<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening for the panel (web group). The widget API and /widget.js are not served through here:
 * the API is stateless JSON and widget.js is a static file that has to be embeddable on other sites.
 */
class SecurityHeaders
{
    public function __construct(private readonly Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
        ];

        if ($request->isSecure() && app()->isProduction()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $dev = $this->viteDevOrigin();
        $connect = array_filter(["'self'", $this->reverbOrigin(), $dev, $dev ? preg_replace('#^http#', 'ws', $dev) : null]);

        $directives = [
            'default-src' => ["'self'"],
            // TODO: Alpine's standard build evaluates expressions with new Function(), hence 'unsafe-eval'.
            // Move to @alpinejs/csp and drop it.
            'script-src' => array_filter(["'self'", "'unsafe-eval'", $dev]),
            'style-src' => array_filter(["'self'", "'unsafe-inline'", $dev]),
            'img-src' => ["'self'", 'data:'],
            'connect-src' => $connect,
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ];

        if ($dev !== null) {
            $directives['font-src'] = ["'self'", 'data:', $dev];
        }

        return implode('; ', array_map(
            fn (string $name, array $sources): string => $name.' '.implode(' ', $sources),
            array_keys($directives),
            $directives,
        ));
    }

    /** ws:// or wss:// origin of the Reverb server the panel connects to (Echo), from config. */
    private function reverbOrigin(): ?string
    {
        $options = (array) config('broadcasting.connections.reverb.options', []);
        $host = $options['host'] ?? null;

        if (! is_string($host) || ! preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            return null;
        }

        $secure = ($options['scheme'] ?? 'https') === 'https';
        $port = (int) ($options['port'] ?? ($secure ? 443 : 80));

        return ($secure ? 'wss' : 'ws').'://'.$host.($port > 0 ? ':'.$port : '');
    }

    /** Origin of the Vite dev server, read from public/hot. Null outside of local development. */
    private function viteDevOrigin(): ?string
    {
        if (! $this->vite->isRunningHot()) {
            return null;
        }

        $url = @file_get_contents($this->vite->hotFile());
        $parts = is_string($url) ? parse_url(trim($url)) : false;

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array($parts['scheme'], ['http', 'https'], true)
            || ! preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
