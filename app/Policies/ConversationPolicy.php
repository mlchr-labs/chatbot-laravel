<?php

namespace App\Policies;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    public function viewAny(User $user, Chatbot $chatbot): Response
    {
        return $chatbot->user_id !== null && $chatbot->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function view(User $user, Conversation $conversation): Response
    {
        $ownerId = $conversation->chatbot?->user_id;

        return $ownerId !== null && $ownerId === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
