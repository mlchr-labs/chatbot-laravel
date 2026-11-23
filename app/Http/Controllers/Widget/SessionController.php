<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\CreateSessionRequest;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Widget\ChatbotAccess;
use App\Services\Widget\OriginValidator;
use App\Services\Widget\WidgetTokenService;
use App\Support\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function __invoke(
        CreateSessionRequest $request,
        ChatbotAccess $access,
        OriginValidator $origins,
        WidgetTokenService $tokens,
    ): JsonResponse {
        $chatbot = $access->byPublicKey($request, $request->validated('public_key'));

        $visitorId = $request->validated('visitor_id') ?: Str::uuid()->toString();

        $conversation = $this->resume($chatbot, $request->validated('conversation_ulid'), $visitorId)
            ?? $chatbot->conversations()->create([
                'visitor_id' => $visitorId,
                'origin' => $origins->hostOf($request),
                'ip_hash' => IpHasher::hash($request->ip()),
                'last_message_at' => now(),
            ]);

        $token = $tokens->issue($conversation->ulid, $chatbot->ulid, $chatbot->public_key);

        $reverb = (array) config('broadcasting.connections.reverb');
        $options = (array) ($reverb['options'] ?? []);

        return response()->json([
            'conversation_ulid' => $conversation->ulid,
            'visitor_id' => $visitorId,
            'widget_token' => $token->value,
            'expires_at' => $token->expiresAt->toIso8601String(),
            'reverb' => [
                'key' => $reverb['key'] ?? null,
                'host' => $options['host'] ?? null,
                'port' => (int) ($options['port'] ?? 443),
                'scheme' => $options['scheme'] ?? 'https',
            ],
            'channel' => 'widget.conversation.'.$conversation->ulid,
            'auth_endpoint' => url('/api/widget/broadcasting/auth'),
            'messages' => $this->recentMessages($conversation),
        ]);
    }

    /** Only a conversation of this chatbot started by this same visitor can be resumed. */
    private function resume(Chatbot $chatbot, ?string $ulid, string $visitorId): ?Conversation
    {
        if ($ulid === null) {
            return null;
        }

        return $chatbot->conversations()
            ->where('ulid', Str::lower($ulid))
            ->where('visitor_id', $visitorId)
            ->first();
    }

    /** @return list<array{id: string, role: string, content: string, created_at: string}> */
    private function recentMessages(Conversation $conversation): array
    {
        return $conversation->messages()
            ->orderByDesc('id')
            ->limit((int) config('widget.history_messages'))
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $message) => [
                'id' => $message->ulid,
                'role' => $message->role->value,
                'content' => $message->content,
                'created_at' => $message->created_at->toIso8601String(),
            ])
            ->all();
    }
}
