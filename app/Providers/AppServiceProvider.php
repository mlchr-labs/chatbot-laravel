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
        $this->configurePanelRateLimiting();
        $this->configureSandboxRateLimiting();
    }

    private function configurePanelRateLimiting(): void
    {
        RateLimiter::for('knowledge-uploads', fn (Request $request) => Limit::perMinute((int) config('knowledge.uploads_per_minute_user'))
            ->by('user:'.($request->user()?->getAuthIdentifier() ?? IpHasher::hash($request->ip()))));
    }

    private function configureSandboxRateLimiting(): void
    {
        RateLimiter::for('sandbox-page', fn (Request $request) => Limit::perMinute((int) config('widget.limits.sandbox_page_per_minute_ip'))
            ->by('ip:'.IpHasher::hash($request->ip())));
    }

    private function configureWidgetRateLimiting(): void
    {
        $tooMany = fn (string $message) => fn (Request $request, array $headers) => response()->json(['message' => $message], 429, $headers);
        $ip = fn (Request $request) => 'ip:'.IpHasher::hash($request->ip());

        RateLimiter::for('widget-config', fn (Request $request) => Limit::perMinute((int) config('widget.limits.config_per_minute_ip'))
            ->by($ip($request))
            ->response($tooMany('Muitas requisições. Tente novamente em instantes.')));

        RateLimiter::for('widget-sessions', function (Request $request) use ($tooMany, $ip) {
            $slowDown = $tooMany('Muitas conexões. Tente novamente em instantes.');

            return [
                Limit::perMinute((int) config('widget.limits.sessions_per_minute_ip'))
                    ->by($ip($request))
                    ->response($slowDown),
                Limit::perDay((int) config('widget.limits.sessions_per_day_ip'))
                    ->by('ip-day:'.IpHasher::hash($request->ip()))
                    ->response($tooMany('Limite diário de conexões atingido. Tente novamente amanhã.')),
            ];
        });

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
                // Everything an owner's chatbots spend on the platform key shares one daily budget.
                Limit::perDay((int) config('widget.limits.messages_per_day_user'))
                    ->by('user:'.$visitor?->chatbot->user_id)
                    ->response($tooMany('Este assistente atingiu o limite diário de mensagens. Tente novamente mais tarde.')),
                Limit::perDay((int) config('widget.limits.messages_per_day_ip_chatbot'))
                    ->by('ip-day:'.$visitor?->chatbot->id.':'.IpHasher::hash($request->ip()))
                    ->response($tooMany('Você atingiu o limite diário de mensagens deste assistente. Tente novamente amanhã.')),
            ];
        });
    }
}
