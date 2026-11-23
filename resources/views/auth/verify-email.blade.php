@extends('layouts.guest')

@section('title', 'Verificar e-mail')

@section('content')
    <h1 class="text-2xl font-bold text-gray-900">Confirme seu e-mail</h1>
    <p class="mt-2 text-sm text-gray-600">
        Enviamos um link de confirmação para o seu e-mail. Clique nele para liberar o acesso.
        Se não chegou, podemos enviar outro.
    </p>

    @if (session('status') === 'verification-link-sent')
        <x-ui.alert type="success" class="mt-6">Um novo link de verificação foi enviado para o seu e-mail.</x-ui.alert>
    @endif

    <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button>Reenviar e-mail de verificação</x-ui.button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm text-gray-600 underline hover:text-gray-900">Sair</button>
        </form>
    </div>
@endsection
