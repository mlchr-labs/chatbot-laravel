<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify exposes limiter keys for login and two-factor only. This runs inside its route group (config
 * fortify.middleware) and throttles the registration and password reset request routes by route name.
 */
class ThrottleAuthForms
{
    /** Route name => named rate limiter. */
    private const LIMITERS = [
        'register.store' => 'register',
        'password.email' => 'forgot-password',
    ];

    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limiter = self::LIMITERS[$request->route()?->getName() ?? ''] ?? null;

        if ($limiter === null) {
            return $next($request);
        }

        // Exactly three arguments: that is how ThrottleRequests tells a named limiter from "max,decay".
        return $this->throttle->handle($request, $next, $limiter);
    }
}
