<?php

namespace Tests\Feature\Widget;

use App\Models\User;

class BroadcastAuthTest extends WidgetTestCase
{
    private function authorizeChannel(string $token, string $channel, string $origin = self::ORIGIN)
    {
        return $this->postJson('/api/widget/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ], $this->headers($token, $origin));
    }

    public function test_valid_token_gets_a_pusher_signature(): void
    {
        $chatbot = $this->chatbot();
        [$conversation, $token] = $this->conversationWithToken($chatbot);
        $channel = 'private-widget.conversation.'.$conversation->ulid;

        $response = $this->authorizeChannel($token, $channel)->assertOk();

        $expected = 'test-key:'.hash_hmac('sha256', "1234.5678:{$channel}", 'test-secret');
        $this->assertSame($expected, $response->json('auth'));
    }

    public function test_mismatched_channel_is_forbidden(): void
    {
        $chatbot = $this->chatbot();
        [, $token] = $this->conversationWithToken($chatbot);
        [$other] = $this->conversationWithToken($chatbot);

        $this->authorizeChannel($token, 'private-widget.conversation.'.$other->ulid)->assertForbidden();
    }

    public function test_visitor_cannot_authorize_panel_channels(): void
    {
        $chatbot = $this->chatbot();
        [, $token] = $this->conversationWithToken($chatbot);
        $user = User::factory()->create();

        $this->authorizeChannel($token, 'private-App.Models.User.'.$user->id)->assertForbidden();
        $this->authorizeChannel($token, 'private-something.else')->assertForbidden();
    }

    public function test_panel_user_without_token_cannot_authorize_widget_channels(): void
    {
        $chatbot = $this->chatbot();
        [$conversation] = $this->conversationWithToken($chatbot);
        $user = $chatbot->user;

        $channel = 'private-widget.conversation.'.$conversation->ulid;

        // Through the widget endpoint (no Bearer token): unauthenticated.
        $this->actingAs($user)
            ->postJson('/api/widget/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $channel], ['Origin' => self::ORIGIN])
            ->assertUnauthorized();

        // Through the panel endpoint (session guard): the channel only accepts the widget guard.
        $this->actingAs($user)
            ->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $channel])
            ->assertForbidden();
    }

    public function test_panel_endpoint_with_a_widget_token_does_not_authorize_panel_channels(): void
    {
        $chatbot = $this->chatbot();
        [, $token] = $this->conversationWithToken($chatbot);

        $this->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-App.Models.User.'.$chatbot->user_id], ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();
    }

    public function test_origin_is_enforced_on_channel_auth(): void
    {
        $chatbot = $this->chatbot();
        [$conversation, $token] = $this->conversationWithToken($chatbot);

        $this->authorizeChannel($token, 'private-widget.conversation.'.$conversation->ulid, 'https://evil.example.org')->assertForbidden();
    }

    public function test_invalid_token_is_401(): void
    {
        $this->authorizeChannel('nope', 'private-widget.conversation.x')->assertUnauthorized();
    }
}
