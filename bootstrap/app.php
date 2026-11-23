<?php

use App\Http\Middleware\EnsureWidgetAccess;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Comma separated proxy IPs/CIDRs allowed to set X-Forwarded-*. Empty trusts none, never "*".
        $middleware->trustProxies(at: array_values(array_filter(
            array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
            static fn (string $proxy): bool => $proxy !== '' && $proxy !== '*' && $proxy !== '**',
        )));

        $middleware->appendToGroup('web', SecurityHeaders::class);

        $middleware->alias([
            'widget.access' => EnsureWidgetAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never flash these into the session after a validation error (Laravel's defaults plus api_key).
        $exceptions->dontFlash(['api_key', 'current_password', 'password', 'password_confirmation']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
