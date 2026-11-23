<?php

namespace Tests\Feature\Widget;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Services\Widget\WidgetTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class WidgetTestCase extends TestCase
{
    use RefreshDatabase;

    protected const ORIGIN = 'https://site.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        // Real (fake-credential) Reverb broadcaster so channel auth signatures are computed; no network is used.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'ws.example.com',
            'broadcasting.connections.reverb.options.port' => 8090,
            'broadcasting.connections.reverb.options.scheme' => 'https',
        ]);

        // Channels are registered on the default driver at boot (the "null" one in tests): register them on reverb too.
        require base_path('routes/channels.php');
    }

    protected function chatbot(array $attributes = []): Chatbot
    {
        return Chatbot::factory()->create($attributes + ['allowed_domains' => ['site.example.com']]);
    }

    /** @return array{Conversation, string} */
    protected function conversationWithToken(Chatbot $chatbot, ?string $visitor = 'visitor-1'): array
    {
        $conversation = Conversation::factory()->create(['chatbot_id' => $chatbot->id, 'visitor_id' => $visitor]);
        $token = app(WidgetTokenService::class)->issue($conversation->ulid, $chatbot->ulid, $chatbot->public_key)->value;

        return [$conversation, $token];
    }

    /** @return array<string, string> */
    protected function headers(string $token, string $origin = self::ORIGIN): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Origin' => $origin];
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // The request guard caches its user for the lifetime of the app instance (one request in production).
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
