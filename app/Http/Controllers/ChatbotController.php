<?php

namespace App\Http\Controllers;

use App\Enums\LlmProvider;
use App\Http\Requests\StoreChatbotRequest;
use App\Http\Requests\UpdateChatbotRequest;
use App\Models\Chatbot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ChatbotController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Chatbot::class);

        $chatbots = $request->user()->chatbots()
            ->withCount(['conversations', 'knowledgeSources'])
            ->latest()
            ->paginate(15);

        return view('chatbots.index', ['chatbots' => $chatbots]);
    }

    public function create(): View
    {
        Gate::authorize('create', Chatbot::class);

        return view('chatbots.create', [
            'chatbot' => new Chatbot(['provider' => LlmProvider::OpenAi, 'temperature' => 0.7, 'is_active' => true]),
            'providers' => LlmProvider::cases(),
        ]);
    }

    public function store(StoreChatbotRequest $request): RedirectResponse
    {
        Gate::authorize('create', Chatbot::class);

        $chatbot = $request->user()->chatbots()->create($request->chatbotAttributes());

        return redirect()->route('chatbots.show', $chatbot)->with('status', 'Chatbot criado com sucesso.');
    }

    public function show(Chatbot $chatbot): View
    {
        Gate::authorize('view', $chatbot);

        $conversations = $chatbot->conversations()
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->limit(10)
            ->get();

        return view('chatbots.show', [
            'chatbot' => $chatbot,
            'conversations' => $conversations,
            'snippet' => '<script src="'.url('widget.js').'" data-chatbot="'.$chatbot->public_key.'" async></script>',
        ]);
    }

    public function edit(Chatbot $chatbot): View
    {
        Gate::authorize('update', $chatbot);

        return view('chatbots.edit', [
            'chatbot' => $chatbot,
            'providers' => LlmProvider::cases(),
        ]);
    }

    public function update(UpdateChatbotRequest $request, Chatbot $chatbot): RedirectResponse
    {
        Gate::authorize('update', $chatbot);

        $chatbot->update($request->chatbotAttributes());

        return redirect()->route('chatbots.show', $chatbot)->with('status', 'Chatbot atualizado.');
    }

    public function destroy(Chatbot $chatbot): RedirectResponse
    {
        Gate::authorize('delete', $chatbot);

        $chatbot->delete();

        return redirect()->route('chatbots.index')->with('status', 'Chatbot excluído.');
    }

    public function rotateKey(Chatbot $chatbot): RedirectResponse
    {
        Gate::authorize('rotateKey', $chatbot);

        $chatbot->rotatePublicKey();

        return redirect()->to(route('chatbots.show', $chatbot).'#widget')
            ->with('status', 'Chave pública gerada. Atualize o snippet nos sites que usam o widget.');
    }
}
