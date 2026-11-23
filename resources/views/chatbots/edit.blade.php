@extends('layouts.app')

@section('title', 'Editar '.$chatbot->name)
@section('heading', 'Editar chatbot')

@section('content')
    <x-ui.card class="mx-auto max-w-3xl">
        <form method="POST" action="{{ route('chatbots.update', $chatbot) }}">
            @csrf
            @method('PUT')
            @include('chatbots._form')
            <div class="mt-8 flex justify-end gap-3">
                <x-ui.button :href="route('chatbots.show', $chatbot)" variant="secondary">Cancelar</x-ui.button>
                <x-ui.button>Salvar alterações</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
