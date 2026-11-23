@extends('layouts.guest')

@section('title', 'Nova senha')

@section('content')
    <h1 class="text-2xl font-bold text-gray-900">Crie uma nova senha</h1>
    <p class="mt-2 text-sm text-gray-600">Escolha uma senha forte que você ainda não usou por aqui.</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-6">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <x-ui.label for="email">E-mail</x-ui.label>
            <x-ui.input name="email" type="email" autocomplete="username" :value="$request->email" required autofocus />
        </div>

        <div>
            <x-ui.label for="password">Nova senha</x-ui.label>
            <x-ui.input name="password" type="password" autocomplete="new-password" required />
        </div>

        <div>
            <x-ui.label for="password_confirmation">Confirmar nova senha</x-ui.label>
            <x-ui.input name="password_confirmation" type="password" autocomplete="new-password" required />
        </div>

        <x-ui.button class="w-full">Redefinir senha</x-ui.button>
    </form>
@endsection
