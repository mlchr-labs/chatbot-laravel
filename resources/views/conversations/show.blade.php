@extends('layouts.app')

@section('title', 'Conversa')
@section('heading', 'Conversa')

@section('content')
    <p class="mb-6 text-sm"><a class="text-indigo-600 hover:text-indigo-700" href="{{ route('chatbots.conversations.index', $chatbot) }}">&larr; Conversas de {{ $chatbot->name }}</a></p>

    <x-ui.card class="mx-auto max-w-3xl">
        <x-slot:header>
            Origem: {{ $conversation->origin ?? 'desconhecida' }}
            <span class="ms-2 text-sm font-normal text-gray-500">{{ $conversation->created_at->format('d/m/Y H:i') }}</span>
        </x-slot:header>

        @if ($messages->isEmpty())
            <p class="text-sm text-gray-600">Esta conversa ainda não tem mensagens.</p>
        @else
            <ol class="space-y-4">
                @foreach ($messages as $message)
                    @php $isUser = $message->role === \App\Enums\MessageRole::User; @endphp
                    <li @class(['flex', 'justify-end' => $isUser])>
                        <div @class([
                            'max-w-[85%] rounded-lg px-4 py-3 text-sm',
                            'bg-indigo-600 text-white' => $isUser,
                            'bg-gray-100 text-gray-900' => ! $isUser,
                        ])>
                            <p @class(['mb-1 text-xs font-medium', 'text-indigo-100' => $isUser, 'text-gray-500' => ! $isUser])>
                                {{ $message->role->label() }} &middot; {{ $message->created_at->format('H:i') }}
                            </p>
                            <p class="break-words whitespace-pre-wrap">{{ $message->content }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>
@endsection
