@extends('layouts.guest')

@section('title', 'Recuperar senha')

@section('content')
    <h1 class="text-2xl font-bold text-gray-900">Esqueceu a senha?</h1>
    <p class="mt-2 text-sm text-gray-600">
        Informe seu e-mail e enviaremos um link para você criar uma nova senha.
    </p>

    @if (session('status'))
        <x-ui.alert type="success" class="mt-6">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-6">
        @csrf

        <div>
            <x-ui.label for="email">E-mail</x-ui.label>
            <x-ui.input name="email" type="email" autocomplete="username" required autofocus />
        </div>

        <x-ui.button class="w-full">Enviar link de recuperação</x-ui.button>

        <p class="text-center text-sm text-gray-600">
            <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:text-indigo-700">Voltar para o login</a>
        </p>
    </form>
@endsection
