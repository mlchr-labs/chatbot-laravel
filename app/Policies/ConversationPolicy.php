<?php

namespace App\Policies;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function viewAny(User $user, Chatbot $chatbot): bool
    {
        return $chatbot->user_id !== null && $chatbot->user_id === $user->id;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        $ownerId = $conversation->chatbot?->user_id;

        return $ownerId !== null && $ownerId === $user->id;
    }
}
