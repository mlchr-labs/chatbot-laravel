<?php

namespace App\Http\Controllers;

use App\Models\Chatbot;
use App\Models\Conversation;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ConversationController extends Controller
{
    public function index(Chatbot $chatbot): View
    {
        Gate::authorize('view', $chatbot);
        Gate::authorize('viewAny', [Conversation::class, $chatbot]);

        $conversations = $chatbot->conversations()
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->paginate(20);

        return view('conversations.index', [
            'chatbot' => $chatbot,
            'conversations' => $conversations,
        ]);
    }

    public function show(Chatbot $chatbot, Conversation $conversation): View
    {
        Gate::authorize('view', $chatbot);
        Gate::authorize('view', $conversation);

        return view('conversations.show', [
            'chatbot' => $chatbot,
            'conversation' => $conversation,
            'messages' => $conversation->messages()->orderBy('id')->get(),
        ]);
    }
}
