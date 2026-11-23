<?php

namespace Tests\Feature\Widget;

use App\Enums\MessageRole;
use App\Events\Widget\ReplyCompleted;
use App\Events\Widget\ReplyFailed;
use App\Events\Widget\ReplyStarted;
use App\Events\Widget\ReplyToken;
use App\Http\Controllers\Widget\MessageController;
use App\Jobs\Chat\GenerateReply;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\ChatResponder;
use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\Exceptions\MissingApiKeyException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GenerateReplyTest extends WidgetTestCase
{
    private function pending(): array
    {
        $chatbot = $this->chatbot();
        $conversation = Conversation::factory()->create(['chatbot_id' => $chatbot->id]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id]);

        $lock = Cache::lock(MessageController::lockKey($conversation->id), 150);
        $this->assertTrue($lock->get());

        return [$conversation, $message, $lock];
    }

    private function bindResponder(callable $behavior): void
    {
        $this->app->bind(ChatResponder::class, fn () => new class($behavior) extends ChatResponder
        {
            public function __construct(private $behavior) {}

            public function respond(Conversation $conversation, Message $userMessage, callable $onToken): Message
            {
                return ($this->behavior)($conversation, $onToken);
            }
        });
    }

    public function test_streams_tokens_then_completes_and_releases_the_lock(): void
    {
        Event::fake([ReplyStarted::class, ReplyToken::class, ReplyCompleted::class, ReplyFailed::class]);
        config(['widget.stream.flush_chars' => 5, 'widget.stream.flush_ms' => 100000]);
        [$conversation, $message, $lock] = $this->pending();

        $this->bindResponder(function (Conversation $c, callable $onToken) {
            foreach (['Olá', ', tudo', ' bem?', ' Sim'] as $delta) {
                $onToken($delta);
            }

            return $c->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Olá, tudo bem? Sim']);
        });

        dispatch_sync(new GenerateReply($message->id, $lock->owner()));

        Event::assertDispatched(ReplyStarted::class, fn ($e) => $e->conversationUlid === $conversation->ulid);
        $deltas = Event::dispatched(ReplyToken::class)->map(fn ($args) => $args[0]->delta)->all();
        $this->assertSame('Olá, tudo bem? Sim', implode('', $deltas));
        $this->assertGreaterThan(1, count($deltas));
        $this->assertLessThan(4, count($deltas));

        $reply = Message::where('role', 'assistant')->firstOrFail();
        Event::assertDispatched(ReplyCompleted::class, fn ($e) => $e->messageUlid === $reply->ulid && $e->content === 'Olá, tudo bem? Sim');
        Event::assertNotDispatched(ReplyFailed::class);

        $this->assertTrue(Cache::lock(MessageController::lockKey($conversation->id), 5)->get(), 'lock must be free');
    }

    public function test_missing_api_key_broadcasts_a_safe_failure_and_releases_the_lock(): void
    {
        Event::fake([ReplyStarted::class, ReplyToken::class, ReplyCompleted::class, ReplyFailed::class]);
        Log::spy();
        [$conversation, $message, $lock] = $this->pending();

        $this->bindResponder(fn () => throw MissingApiKeyException::forProvider('openai'));

        dispatch_sync(new GenerateReply($message->id, $lock->owner()));

        Event::assertDispatched(ReplyFailed::class, fn ($e) => $e->message === 'Este assistente ainda não está configurado.'
            && $e->conversationUlid === $conversation->ulid);
        Event::assertNotDispatched(ReplyCompleted::class);
        Log::shouldHaveReceived('warning')->once();
        $this->assertTrue(Cache::lock(MessageController::lockKey($conversation->id), 5)->get());
    }

    public function test_provider_failure_does_not_leak_details_to_the_visitor(): void
    {
        Event::fake([ReplyStarted::class, ReplyToken::class, ReplyCompleted::class, ReplyFailed::class]);
        [, $message, $lock] = $this->pending();

        $this->bindResponder(fn () => throw LlmException::fromStatus(429));

        dispatch_sync(new GenerateReply($message->id, $lock->owner()));

        Event::assertDispatched(ReplyFailed::class, fn ($e) => $e->message === 'Não consegui responder agora. Tente novamente em instantes.');
    }

    public function test_unexpected_errors_are_contained_and_logged_by_class_only(): void
    {
        Event::fake([ReplyStarted::class, ReplyToken::class, ReplyCompleted::class, ReplyFailed::class]);
        Log::spy();
        [$conversation, $message, $lock] = $this->pending();

        $this->bindResponder(fn () => throw new RuntimeException('Bearer sk-secret'));

        dispatch_sync(new GenerateReply($message->id, $lock->owner()));

        Event::assertDispatched(ReplyFailed::class);
        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => ! str_contains(json_encode($ctx), 'sk-secret'))->once();
        $this->assertTrue(Cache::lock(MessageController::lockKey($conversation->id), 5)->get());
    }

    public function test_lock_is_only_released_by_its_owner(): void
    {
        Event::fake([ReplyStarted::class, ReplyToken::class, ReplyCompleted::class, ReplyFailed::class]);
        [$conversation, $message] = $this->pending();

        $this->bindResponder(fn () => throw LlmException::unreachable());

        dispatch_sync(new GenerateReply($message->id, 'someone-else'));

        $this->assertFalse(Cache::lock(MessageController::lockKey($conversation->id), 5)->get());
    }

    public function test_events_use_the_private_widget_channel_and_expected_names(): void
    {
        $events = [
            [new ReplyStarted('abc'), 'reply.started', []],
            [new ReplyToken('abc', 'oi'), 'reply.token', ['delta' => 'oi']],
            [new ReplyCompleted('abc', 'm1', 'texto'), 'reply.completed', ['id' => 'm1', 'content' => 'texto']],
            [new ReplyFailed('abc', 'erro'), 'reply.failed', ['message' => 'erro']],
        ];

        foreach ($events as [$event, $name, $payload]) {
            $channels = $event->broadcastOn();
            $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
            $this->assertSame('private-widget.conversation.abc', $channels[0]->name);
            $this->assertSame($name, $event->broadcastAs());
            $this->assertSame($payload, $event->broadcastWith());
        }
    }

    public function test_job_configuration(): void
    {
        $job = new GenerateReply(1, 'owner');

        $this->assertSame('chat', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(120, $job->timeout);
    }
}
