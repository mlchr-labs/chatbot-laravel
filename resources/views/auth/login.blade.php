@extends('layouts.guest')

@section('title', 'Entrar')

@section('content')
    <h1 class="text-2xl font-bold text-gray-900">Entrar na sua conta</h1>
    <p class="mt-2 text-sm text-gray-600">
        Ainda não tem conta?
        <a href="{{ route('register') }}" class="font-medium text-indigo-600 underline hover:text-indigo-700">Cadastre-se</a>
    </p>

    @if (session('status'))
        <x-ui.alert type="success" class="mt-6">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-6">
        @csrf

        <div>
            <x-ui.label for="email">E-mail</x-ui.label>
            <x-ui.input name="email" type="email" autocomplete="username" required autofocus />
        </div>

        <div>
            <div class="flex items-center justify-between">
                <x-ui.label for="password">Senha</x-ui.label>
                <a href="{{ route('password.request') }}" class="text-sm text-indigo-600 hover:text-indigo-700">Esqueceu a senha?</a>
            </div>
            <x-ui.input name="password" type="password" autocomplete="current-password" required />
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600">
            Manter conectado
        </label>

        <x-ui.button class="w-full">Entrar</x-ui.button>
    </form>
@endsection
