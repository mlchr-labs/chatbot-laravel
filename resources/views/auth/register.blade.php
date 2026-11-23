@extends('layouts.guest')

@section('title', 'Criar conta')

@section('content')
    <h1 class="text-2xl font-bold text-gray-900">Crie sua conta</h1>
    <p class="mt-2 text-sm text-gray-600">
        Já tem conta?
        <a href="{{ route('login') }}" class="font-medium text-indigo-600 underline hover:text-indigo-700">Entrar</a>
    </p>

    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-6">
        @csrf

        <div>
            <x-ui.label for="name">Nome</x-ui.label>
            <x-ui.input name="name" autocomplete="name" required autofocus />
        </div>

        <div>
            <x-ui.label for="email">E-mail</x-ui.label>
            <x-ui.input name="email" type="email" autocomplete="username" required />
        </div>

        <div>
            <x-ui.label for="password">Senha</x-ui.label>
            <x-ui.input name="password" type="password" autocomplete="new-password" required />
        </div>

        <div>
            <x-ui.label for="password_confirmation">Confirmar senha</x-ui.label>
            <x-ui.input name="password_confirmation" type="password" autocomplete="new-password" required />
        </div>

        <x-ui.button class="w-full">Criar conta</x-ui.button>
    </form>
@endsection
