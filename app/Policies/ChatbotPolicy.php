<?php

namespace App\Policies;

use App\Models\Chatbot;
use App\Models\User;

class ChatbotPolicy
{
    /** Listings are always scoped to the user's own chatbots by the controller. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Chatbot $chatbot): bool
    {
        return $this->owns($user, $chatbot);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Chatbot $chatbot): bool
    {
        return $this->owns($user, $chatbot);
    }

    public function delete(User $user, Chatbot $chatbot): bool
    {
        return $this->owns($user, $chatbot);
    }

    public function rotateKey(User $user, Chatbot $chatbot): bool
    {
        return $this->owns($user, $chatbot);
    }

    private function owns(User $user, Chatbot $chatbot): bool
    {
        return $chatbot->user_id !== null && $chatbot->user_id === $user->id;
    }
}
