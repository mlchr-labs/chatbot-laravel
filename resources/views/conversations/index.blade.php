@extends('layouts.app')

@section('title', 'Conversas de '.$chatbot->name)
@section('heading', 'Conversas')

@section('content')
    <p class="mb-6 text-sm"><a class="text-indigo-600 hover:text-indigo-700" href="{{ route('chatbots.show', $chatbot) }}#conversations">&larr; {{ $chatbot->name }}</a></p>

    @if ($conversations->isEmpty())
        <x-ui.empty-state title="Nenhuma conversa ainda" description="As conversas dos visitantes aparecem aqui assim que o widget estiver no ar." />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Visitante</th>
                <th>Origem</th>
                <th>Mensagens</th>
                <th>Última atividade</th>
                <th><span class="sr-only">Ações</span></th>
            </x-slot:head>
            @foreach ($conversations as $conversation)
                <tr>
                    <td class="font-mono text-xs">{{ \Illuminate\Support\Str::limit($conversation->visitor_id ?? '-', 12) }}</td>
                    <td>{{ $conversation->origin ?? '-' }}</td>
                    <td>{{ $conversation->messages_count }}</td>
                    <td>{{ $conversation->last_message_at?->diffForHumans() ?? '-' }}</td>
                    <td class="text-end"><a class="font-medium text-indigo-600 hover:text-indigo-700" href="{{ route('chatbots.conversations.show', [$chatbot, $conversation]) }}">Ver</a></td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="mt-6">{{ $conversations->links() }}</div>
    @endif
@endsection
