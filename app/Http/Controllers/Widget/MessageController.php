<?php

namespace App\Http\Controllers\Widget;

use App\Auth\WidgetVisitor;
use App\Enums\MessageRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreMessageRequest;
use App\Jobs\Chat\GenerateReply;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var WidgetVisitor $visitor */
        $visitor = $request->user('widget');

        // The token is bound to one conversation. Anything else looks like it does not exist.
        if ($visitor->conversation->ulid !== $conversation->ulid) {
            abort(404);
        }

        $lock = Cache::lock(self::lockKey($conversation->id), (int) config('widget.reply_lock_seconds'));

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Aguarde a resposta anterior terminar antes de enviar outra mensagem.',
            ], 409);
        }

        try {
            $message = $conversation->messages()->create([
                'role' => MessageRole::User,
                'content' => $request->validated('content'),
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            GenerateReply::dispatch($message->id, $lock->owner());
        } catch (Throwable $e) {
            $lock->release();

            throw $e;
        }

        return response()->json(['id' => $message->ulid], 202);
    }

    public static function lockKey(int $conversationId): string
    {
        return 'widget:reply-lock:'.$conversationId;
    }
}
