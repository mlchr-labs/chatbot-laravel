<?php

namespace App\Auth;

use App\Models\Chatbot;
use App\Models\Conversation;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The "user" of the widget guard: an anonymous visitor bound to one conversation of one chatbot.
 */
final class WidgetVisitor implements Authenticatable
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly Chatbot $chatbot,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'conversation_ulid';
    }

    public function getAuthIdentifier(): string
    {
        return $this->conversation->ulid;
    }

    public function getAuthPasswordName(): string
    {
        return '';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
