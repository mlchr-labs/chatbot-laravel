@extends('layouts.app')

@section('title', 'Novo chatbot')
@section('heading', 'Novo chatbot')

@section('content')
    <x-ui.card class="mx-auto max-w-3xl">
        <form method="POST" action="{{ route('chatbots.store') }}">
            @csrf
            @include('chatbots._form')
            <div class="mt-8 flex justify-end gap-3">
                <x-ui.button :href="route('chatbots.index')" variant="secondary">Cancelar</x-ui.button>
                <x-ui.button>Criar chatbot</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
