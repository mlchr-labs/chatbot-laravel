<?php

namespace App\Providers;

use App\Services\Widget\WidgetTokenService;
use App\Services\Widget\WidgetVisitorResolver;
use App\Support\IpHasher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        $this->app->singleton(WidgetTokenService::class, fn () => new WidgetTokenService(
            appKey: (string) config('app.key'),
            ttlMinutes: (int) config('widget.token_ttl_minutes'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::viaRequest('widget', fn (Request $request) => $this->app->make(WidgetVisitorResolver::class)->resolve($request));

        $this->configureWidgetRateLimiting();
    }

    private function configureWidgetRateLimiting(): void
    {
        $tooMany = fn (string $message) => fn (Request $request, array $headers) => response()->json(['message' => $message], 429, $headers);
        $ip = fn (Request $request) => 'ip:'.IpHasher::hash($request->ip());

        RateLimiter::for('widget-config', fn (Request $request) => Limit::perMinute((int) config('widget.limits.config_per_minute_ip'))
            ->by($ip($request))
            ->response($tooMany('Muitas requisições. Tente novamente em instantes.')));

        RateLimiter::for('widget-sessions', fn (Request $request) => Limit::perMinute((int) config('widget.limits.sessions_per_minute_ip'))
            ->by($ip($request))
            ->response($tooMany('Muitas conexões. Tente novamente em instantes.')));

        RateLimiter::for('widget-broadcast-auth', fn (Request $request) => Limit::perMinute((int) config('widget.limits.broadcast_auth_per_minute_ip'))
            ->by($ip($request))
            ->response($tooMany('Muitas requisições. Tente novamente em instantes.')));

        // Runs after auth:widget, so the visitor (conversation and chatbot) is known.
        RateLimiter::for('widget-messages', function (Request $request) use ($tooMany, $ip) {
            $visitor = $request->user('widget');
            $slowDown = $tooMany('Você está enviando mensagens rápido demais. Aguarde um instante.');

            return [
                Limit::perMinute((int) config('widget.limits.messages_per_minute_conversation'))
                    ->by('conversation:'.$visitor?->getAuthIdentifier())
                    ->response($slowDown),
                Limit::perMinute((int) config('widget.limits.messages_per_minute_ip'))
                    ->by($ip($request))
                    ->response($slowDown),
                Limit::perDay((int) config('widget.limits.messages_per_day_chatbot'))
                    ->by('chatbot:'.$visitor?->chatbot->id)
                    ->response($tooMany('Este assistente atingiu o limite diário de mensagens. Tente novamente mais tarde.')),
            ];
        });
    }
}
