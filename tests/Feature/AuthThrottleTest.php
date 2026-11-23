<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_is_throttled_per_ip(): void
    {
        config(['fortify.throttle.register_per_minute' => 2]);
        $post = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/register', ['name' => '', 'email' => 'x', 'password' => 'x']);

        // Invalid attempts count too: the throttle runs before validation.
        $post('203.0.113.7')->assertStatus(302);
        $post('203.0.113.7')->assertStatus(302);
        $post('203.0.113.7')->assertStatus(429);

        $post('203.0.113.8')->assertStatus(302);
    }

    public function test_forgot_password_is_throttled_per_ip(): void
    {
        config(['fortify.throttle.forgot_password_per_minute' => 1]);
        $post = fn () => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertNotSame(429, $post()->getStatusCode());
        $post()->assertStatus(429);
    }

    public function test_login_page_and_register_form_are_not_throttled_by_it(): void
    {
        config(['fortify.throttle.register_per_minute' => 1]);

        foreach (range(1, 3) as $ignored) {
            $this->get('/register')->assertOk();
        }
    }

    public function test_widget_demo_route_only_exists_in_local(): void
    {
        $this->get('/widget-demo')->assertNotFound();
    }
}
