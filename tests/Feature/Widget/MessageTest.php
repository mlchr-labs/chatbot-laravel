<?php

namespace Tests\Feature\Widget;

use App\Http\Controllers\Widget\MessageController;
use App\Jobs\Chat\GenerateReply;
use App\Models\Message;
use App\Services\Widget\WidgetTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class MessageTest extends WidgetTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function send(string $ulid, string $token, array $body = ['content' => 'Olá'], string $origin = self::ORIGIN)
    {
        return $this->postJson("/api/widget/conversations/{$ulid}/messages", $body, $this->headers($token, $origin));
    }

    public function test_persists_the_message_and_dispatches_the_job_on_the_chat_queue(): void
    {
        $chatbot = $this->chatbot();
        [$conversation, $token] = $this->conversationWithToken($chatbot);

        $response = $this->send($conversation->ulid, $token, ['content' => 'Qual o horário?'])->assertStatus(202);

        $message = Message::firstOrFail();
        $this->assertSame($message->ulid, $response->json('id'));
        $this->assertSame('Qual o horário?', $message->content);
        $this->assertSame('user', $message->role->value);
        $this->assertSame($conversation->id, $message->conversation_id);

        Queue::assertPushedOn('chat', GenerateReply::class, fn (GenerateReply $job) => $job->messageId === $message->id);
    }

    public function test_token_of_conversation_a_cannot_post_to_b(): void
    {
        $chatbot = $this->chatbot();
        [, $tokenA] = $this->conversationWithToken($chatbot);
        [$b] = $this->conversationWithToken($chatbot);

        $this->send($b->ulid, $tokenA)->assertNotFound();
        $this->send('01hzzzzzzzzzzzzzzzzzzzzzzz', $tokenA)->assertNotFound();

        $this->assertSame(0, Message::count());
        Queue::assertNothingPushed();
    }

    public function test_missing_and_invalid_tokens_are_401(): void
    {
        $chatbot = $this->chatbot();
        [$conversation] = $this->conversationWithToken($chatbot);

        $this->postJson("/api/widget/conversations/{$conversation->ulid}/messages", ['content' => 'x'], ['Origin' => self::ORIGIN])
            ->assertUnauthorized();
        $this->send($conversation->ulid, 'garbage')->assertUnauthorized();
    }

    public function test_expired_token_is_401(): void
    {
        $chatbot = $this->chatbot();
        [$conversation, $token] = $this->conversationWithToken($chatbot);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(config('widget.token_ttl_minutes') + 1));
        try {
            $this->send($conversation->ulid, $token)->assertUnauthorized();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_token_for_a_chatbot_that_does_not_match_the_conversation_is_rejected(): void
    {
        $chatbot = $this->chatbot();
        $other = $this->chatbot();
        [$conversation] = $this->conversationWithToken($chatbot);
        $forged = app(WidgetTokenService::class)->issue($conversation->ulid, $other->ulid)->value;

        $this->send($conversation->ulid, $forged)->assertUnauthorized();
    }

    public function test_origin_and_active_flag_are_rechecked_on_every_call(): void
    {
        $chatbot = $this->chatbot();
        [$conversation, $token] = $this->conversationWithToken($chatbot);

        $this->send($conversation->ulid, $token, origin: 'https://evil.example.org')->assertForbidden();
        $this->postJson("/api/widget/conversations/{$conversation->ulid}/messages", ['content' => 'x'], ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();

        $chatbot->update(['allowed_domains' => []]);
        $this->send($conversation->ulid, $token)->assertForbidden();

        $chatbot->update(['allowed_domains' => ['site.example.com'], 'is_active' => false]);
        $this->send($conversation->ulid, $token)->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_content_is_validated(): void
    {
        $chatbot = $this->chatbot();
        [$conversation, $token] = $this->conversationWithToken($chatbot);

        $this->send($conversation->ulid, $token, ['content' => str_repeat('a', 2001)])
            ->assertStatus(422)->assertJsonValidationErrors('content');
        $this->send($conversation->ulid, $token, ['content' => ''])->assertStatus(422);
        $this->send($conversation->ulid, $token, ['content' => ['x']])->assertStatus(422);
        $this->send($conversation->ulid, $token, ['content' => str_repeat('a', 2000)])->assertStatus(202);
    }

    public function test_second_message_while_a_reply_is_in_progress_is_409_and_lock_is_freed_by_the_job_owner(): void
    {
        $chatbot = $this->chatbot();
        [$conversation, $token] = $this->conversationWithToken($chatbot);

        $this->send($conversation->ulid, $token)->assertStatus(202);
        $this->send($conversation->ulid, $token)->assertStatus(409);
        $this->assertSame(1, Message::count());

        Cache::lock(MessageController::lockKey($conversation->id))->forceRelease();
        $this->send($conversation->ulid, $token)->assertStatus(202);
    }

    public function test_per_conversation_rate_limit_is_429_in_portuguese(): void
    {
        config(['widget.limits.messages_per_minute_conversation' => 2]);
        $chatbot = $this->chatbot();
        [$conversation, $token] = $this->conversationWithToken($chatbot);
        $lock = fn () => Cache::lock(MessageController::lockKey($conversation->id))->forceRelease();

        $this->send($conversation->ulid, $token)->assertStatus(202);
        $lock();
        $this->send($conversation->ulid, $token)->assertStatus(202);
        $lock();
        $this->send($conversation->ulid, $token)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Você está enviando mensagens rápido demais. Aguarde um instante.');
        $this->assertSame(2, Message::count());
    }

    public function test_per_chatbot_daily_quota_is_429(): void
    {
        config(['widget.limits.messages_per_day_chatbot' => 1]);
        $chatbot = $this->chatbot();
        [$a, $tokenA] = $this->conversationWithToken($chatbot);
        [$b, $tokenB] = $this->conversationWithToken($chatbot);

        $this->send($a->ulid, $tokenA)->assertStatus(202);
        $this->send($b->ulid, $tokenB)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Este assistente atingiu o limite diário de mensagens. Tente novamente mais tarde.');
    }
}
