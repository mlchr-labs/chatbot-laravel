<!DOCTYPE html>
<html lang="pt-BR" class="h-full bg-gray-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Sandbox: {{ $name }}</title>
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="h-full font-sans text-gray-900 antialiased">
        <main class="mx-auto max-w-2xl px-6 py-16">
            <p class="text-sm font-medium text-indigo-600">Ambiente de teste</p>
            <h1 class="mt-2 text-3xl font-bold">{{ $name }}</h1>
            <p class="mt-4 text-gray-600">
                Esta página existe só para testar o chatbot. Use o widget no canto da tela para conversar e ver como ele responde.
                As conversas aqui são reais e usam a base de conhecimento do chatbot.
            </p>
        </main>

        {{-- The snippet escapes its own attributes (see Chatbot::widgetSnippet()). --}}
        {!! $snippet !!}
    </body>
</html>
