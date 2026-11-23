<?php

namespace App\Jobs\Chat;

use App\Events\Widget\ReplyCompleted;
use App\Events\Widget\ReplyFailed;
use App\Events\Widget\ReplyStarted;
use App\Events\Widget\ReplyToken;
use App\Http\Controllers\Widget\MessageController;
use App\Models\Message;
use App\Services\Chat\ChatResponder;
use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\Exceptions\MissingApiKeyException;
use App\Services\Widget\TokenBuffer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  string  $lockOwner  owner token of the "reply in progress" lock taken by the controller
     */
    public function __construct(
        public readonly int $messageId,
        public readonly string $lockOwner,
    ) {
        $this->onQueue('chat');
    }

    public function handle(ChatResponder $responder): void
    {
        $message = Message::query()->with('conversation.chatbot')->find($this->messageId);

        if ($message === null) {
            $this->releaseLock(null);

            return;
        }

        $conversation = $message->conversation;
        $ulid = $conversation->ulid;

        try {
            ReplyStarted::dispatch($ulid);

            $buffer = new TokenBuffer(
                emit: fn (string $delta) => ReplyToken::dispatch($ulid, $delta),
                flushChars: (int) config('widget.stream.flush_chars'),
                flushMs: (int) config('widget.stream.flush_ms'),
            );

            $reply = $responder->respond($conversation, $message, $buffer->add(...));
            $buffer->flush();

            ReplyCompleted::dispatch($ulid, $reply->ulid);
        } catch (MissingApiKeyException $e) {
            $this->replyFailed($ulid, $conversation->chatbot_id, $e, 'Este assistente ainda não está configurado.');
        } catch (LlmException $e) {
            $this->replyFailed($ulid, $conversation->chatbot_id, $e, 'Não consegui responder agora. Tente novamente em instantes.');
        } catch (Throwable $e) {
            // Unknown error: log only the class, the message could carry request data.
            Log::error('Widget reply failed unexpectedly', [
                'chatbot_id' => $conversation->chatbot_id,
                'conversation_id' => $conversation->id,
                'exception' => $e::class,
            ]);
            ReplyFailed::dispatch($ulid, 'Não consegui responder agora. Tente novamente em instantes.');
        } finally {
            $this->releaseLock($conversation->id);
        }
    }

    private function replyFailed(string $ulid, int $chatbotId, LlmException $e, string $visitorMessage): void
    {
        // LlmException messages never carry keys or provider bodies (see the class docblock).
        Log::warning('Widget reply failed', [
            'chatbot_id' => $chatbotId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        ReplyFailed::dispatch($ulid, $visitorMessage);
    }

    /** Job killed or failed outside handle(): free the conversation anyway. */
    public function failed(?Throwable $exception): void
    {
        $conversationId = Message::query()->whereKey($this->messageId)->value('conversation_id');

        $this->releaseLock($conversationId);
    }

    private function releaseLock(?int $conversationId): void
    {
        $conversationId ??= Message::query()->whereKey($this->messageId)->value('conversation_id');

        if ($conversationId === null) {
            return;
        }

        Cache::restoreLock(MessageController::lockKey((int) $conversationId), $this->lockOwner)->release();
    }
}
