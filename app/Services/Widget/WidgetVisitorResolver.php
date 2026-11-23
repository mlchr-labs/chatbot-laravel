<?php

namespace App\Services\Widget;

use App\Auth\WidgetVisitor;
use App\Models\Conversation;
use Illuminate\Http\Request;

class WidgetVisitorResolver
{
    public function __construct(private readonly WidgetTokenService $tokens) {}

    public function resolve(Request $request): ?WidgetVisitor
    {
        $payload = $this->tokens->verify($request->bearerToken());
        if ($payload === null) {
            return null;
        }

        $conversation = Conversation::query()
            ->with('chatbot')
            ->where('ulid', $payload->conversationUlid)
            ->first();

        // The conversation must still belong to the chatbot the token was issued for.
        if ($conversation === null || $conversation->chatbot->ulid !== $payload->chatbotUlid) {
            return null;
        }

        // Rotating the public key revokes every token issued under the previous one.
        if (! hash_equals(WidgetTokenService::keyVersion((string) $conversation->chatbot->public_key), $payload->keyVersion)) {
            return null;
        }

        return new WidgetVisitor($conversation, $conversation->chatbot);
    }
}
