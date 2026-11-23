@extends('layouts.app')

@section('title', 'Chatbots')
@section('heading', 'Chatbots')

@section('content')
    @if ($chatbots->isEmpty())
        <x-ui.empty-state title="Nenhum chatbot por aqui ainda" description="Crie o primeiro chatbot, suba a base de conhecimento e publique o widget no seu site.">
            <x-slot:action>
                <x-ui.button :href="route('chatbots.create')">Criar chatbot</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
    @else
        <div class="mb-6 flex justify-end">
            <x-ui.button :href="route('chatbots.create')">Novo chatbot</x-ui.button>
        </div>

        <x-ui.table>
            <x-slot:head>
                <th>Nome</th>
                <th>Provedor</th>
                <th>Modelo</th>
                <th>Status</th>
                <th>Conversas</th>
                <th><span class="sr-only">Ações</span></th>
            </x-slot:head>

            @foreach ($chatbots as $chatbot)
                <tr>
                    <td class="font-medium text-gray-900">{{ $chatbot->name }}</td>
                    <td>{{ $chatbot->provider->label() }}</td>
                    <td>{{ $chatbot->model }}</td>
                    <td>
                        <x-ui.badge :color="$chatbot->is_active ? 'green' : 'gray'">{{ $chatbot->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge>
                    </td>
                    <td>{{ $chatbot->conversations_count }}</td>
                    <td class="text-end whitespace-nowrap">
                        <a href="{{ route('chatbots.show', $chatbot) }}" class="font-medium text-indigo-600 hover:text-indigo-700">Abrir</a>
                        <a href="{{ route('chatbots.edit', $chatbot) }}" class="ms-4 font-medium text-gray-600 hover:text-gray-900">Editar</a>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="mt-6">{{ $chatbots->links() }}</div>
    @endif
@endsection
