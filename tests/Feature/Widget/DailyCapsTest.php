<?php

namespace Tests\Feature\Widget;

use App\Models\Message;
use Illuminate\Support\Facades\Queue;

class DailyCapsTest extends WidgetTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function send(string $ulid, string $token, string $ip = '203.0.113.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson("/api/widget/conversations/{$ulid}/messages", ['content' => 'Olá'], $this->headers($token));
    }

    public function test_daily_quota_is_shared_by_every_chatbot_of_the_same_user(): void
    {
        config(['widget.limits.messages_per_day_user' => 2]);
        $first = $this->chatbot();
        $second = $this->chatbot(['user_id' => $first->user_id]);
        $foreign = $this->chatbot();
        [$a, $tokenA] = $this->conversationWithToken($first);
        [$b, $tokenB] = $this->conversationWithToken($second);
        [$c, $tokenC] = $this->conversationWithToken($foreign);

        $this->send($a->ulid, $tokenA, '203.0.113.1')->assertStatus(202);
        $this->send($b->ulid, $tokenB, '203.0.113.2')->assertStatus(202);
        $this->send($a->ulid, $tokenA, '203.0.113.3')->assertStatus(429);

        // Another owner has its own budget.
        $this->send($c->ulid, $tokenC, '203.0.113.4')->assertStatus(202);
    }

    public function test_per_chatbot_quota_still_applies_next_to_the_user_quota(): void
    {
        config(['widget.limits.messages_per_day_chatbot' => 1, 'widget.limits.messages_per_day_user' => 100]);
        $chatbot = $this->chatbot();
        [$a, $tokenA] = $this->conversationWithToken($chatbot);
        [$b, $tokenB] = $this->conversationWithToken($chatbot);

        $this->send($a->ulid, $tokenA, '203.0.113.1')->assertStatus(202);
        $this->send($b->ulid, $tokenB, '203.0.113.2')->assertStatus(429);
    }

    public function test_messages_per_ip_per_chatbot_daily_cap(): void
    {
        config(['widget.limits.messages_per_day_ip_chatbot' => 2]);
        $chatbot = $this->chatbot();
        $other = $this->chatbot();
        [$a, $tokenA] = $this->conversationWithToken($chatbot);
        [$b, $tokenB] = $this->conversationWithToken($chatbot);
        [$c, $tokenC] = $this->conversationWithToken($other);
        [$d, $tokenD] = $this->conversationWithToken($chatbot);

        // Two different conversations from the same address count together.
        $this->send($a->ulid, $tokenA)->assertStatus(202);
        $this->send($b->ulid, $tokenB)->assertStatus(202);
        $this->send($d->ulid, $tokenD)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Você atingiu o limite diário de mensagens deste assistente. Tente novamente amanhã.');

        // Same address on another chatbot, and another address on the same chatbot, are untouched.
        $this->send($c->ulid, $tokenC)->assertStatus(202);
        $this->send($d->ulid, $tokenD, '203.0.113.99')->assertStatus(202);
        $this->assertSame(4, Message::count());
    }

    public function test_sessions_per_ip_daily_cap(): void
    {
        config(['widget.limits.sessions_per_day_ip' => 2]);
        $chatbot = $this->chatbot();
        $open = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/widget/sessions', ['public_key' => $chatbot->public_key], ['Origin' => self::ORIGIN]);

        $open('203.0.113.10')->assertOk();
        $open('203.0.113.10')->assertOk();
        $open('203.0.113.10')->assertStatus(429)
            ->assertJsonPath('message', 'Limite diário de conexões atingido. Tente novamente amanhã.');

        $open('203.0.113.11')->assertOk();
    }
}
