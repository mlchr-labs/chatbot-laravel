<?php

namespace App\Policies;

use App\Models\Chatbot;
use App\Models\KnowledgeSource;
use App\Models\User;

class KnowledgeSourcePolicy
{
    public function viewAny(User $user, Chatbot $chatbot): bool
    {
        return $this->ownsChatbot($user, $chatbot);
    }

    public function view(User $user, KnowledgeSource $source): bool
    {
        return $this->owns($user, $source);
    }

    public function create(User $user, Chatbot $chatbot): bool
    {
        return $this->ownsChatbot($user, $chatbot);
    }

    public function delete(User $user, KnowledgeSource $source): bool
    {
        return $this->owns($user, $source);
    }

    private function owns(User $user, KnowledgeSource $source): bool
    {
        return $source->user_id !== null && $source->user_id === $user->id;
    }

    private function ownsChatbot(User $user, Chatbot $chatbot): bool
    {
        return $chatbot->user_id !== null && $chatbot->user_id === $user->id;
    }
}
