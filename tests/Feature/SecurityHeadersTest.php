<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private string $hot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->hot = public_path('hot');
        @unlink($this->hot);
        config([
            'broadcasting.connections.reverb.options.host' => 'ws.example.com',
            'broadcasting.connections.reverb.options.port' => 8090,
            'broadcasting.connections.reverb.options.scheme' => 'https',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->hot);

        parent::tearDown();
    }

    private function panel()
    {
        return $this->actingAs(User::factory()->create())->get(route('chatbots.index'));
    }

    public function test_panel_pages_carry_the_hardening_headers(): void
    {
        $response = $this->panel()->assertOk();

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeaderMissing('Strict-Transport-Security');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-eval'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringContainsString("connect-src 'self' wss://ws.example.com:8090", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringNotContainsString('localhost:5173', $csp);
    }

    public function test_guest_pages_get_them_too(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Content-Security-Policy');
    }

    public function test_hsts_only_when_secure_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        $this->app['env'] = 'testing';
        $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_plain_ws_origin_when_reverb_is_http(): void
    {
        config(['broadcasting.connections.reverb.options.scheme' => 'http', 'broadcasting.connections.reverb.options.port' => 8090]);

        $this->assertStringContainsString("connect-src 'self' ws://ws.example.com:8090", $this->panel()->headers->get('Content-Security-Policy'));
    }

    public function test_vite_dev_server_is_allowed_only_while_the_hot_file_exists(): void
    {
        file_put_contents($this->hot, "http://localhost:5173\n");

        $csp = $this->panel()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'unsafe-eval' http://localhost:5173", $csp);
        $this->assertStringContainsString("connect-src 'self' wss://ws.example.com:8090 http://localhost:5173 ws://localhost:5173", $csp);

        file_put_contents($this->hot, "javascript:alert(1); script-src *\n");
        $this->assertStringNotContainsString('alert', $this->panel()->headers->get('Content-Security-Policy'));
    }

    public function test_the_widget_api_is_not_given_frame_or_csp_headers(): void
    {
        $response = $this->getJson('/api/widget/config/pk_unknown');

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Cross-Origin-Resource-Policy');
    }

    public function test_forwarded_headers_from_unlisted_proxies_are_ignored(): void
    {
        Route::get('/_ip', fn (Request $request) => $request->ip().'|'.($request->isSecure() ? 'https' : 'http'));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get('/_ip', ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('203.0.113.5|http');
    }
}
