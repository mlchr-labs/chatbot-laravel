<?php

namespace App\Http\Middleware;

use App\Auth\WidgetVisitor;
use App\Services\Widget\ChatbotAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs after auth:widget. Re-validates is_active and Origin on every call. */
class EnsureWidgetAccess
{
    public function __construct(private readonly ChatbotAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $visitor = $request->user('widget');

        if (! $visitor instanceof WidgetVisitor) {
            abort(401, 'Token do widget inválido ou expirado.');
        }

        $this->access->assertAllowed($request, $visitor->chatbot);

        return $next($request);
    }
}
