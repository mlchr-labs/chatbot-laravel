@extends('layouts.app')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
    <x-ui.card class="mx-auto max-w-2xl text-center">
        <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-indigo-100 text-indigo-700">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
        </div>
        <h2 class="mt-4 text-lg font-semibold text-gray-900">Nenhum chatbot por aqui ainda</h2>
        <p class="mt-2 text-sm text-gray-600">
            Quando você criar o primeiro chatbot, as métricas e conversas aparecem nesta página.
        </p>
        <div class="mt-6">
            <x-ui.button :href="Route::has('chatbots.index') ? route('chatbots.index') : '#'">Ir para Chatbots</x-ui.button>
        </div>
    </x-ui.card>
@endsection
