<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-white">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@hasSection('title')@yield('title') | @endif{{ config('app.name') }}</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans text-gray-900 antialiased">
        <section class="lg:grid lg:min-h-screen lg:grid-cols-12">
            <aside class="relative hidden bg-indigo-700 lg:col-span-5 lg:flex lg:items-end xl:col-span-6" aria-hidden="true">
                <div class="absolute inset-0 bg-linear-to-br from-indigo-600 to-indigo-900"></div>
                <div class="relative p-12 text-white">
                    <a href="{{ url('/') }}" class="inline-flex items-center gap-2 text-lg font-semibold">
                        <svg class="size-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM12 20.25c4.97 0 9-3.694 9-8.25S16.97 3.75 12 3.75 3 7.444 3 12c0 1.6.5 3.09 1.36 4.35L3.75 20.25l4.2-.84A9.9 9.9 0 0 0 12 20.25Z" />
                        </svg>
                        {{ config('app.name') }}
                    </a>
                    <h2 class="mt-6 text-3xl font-bold sm:text-4xl">Atendimento inteligente com a sua base de conhecimento</h2>
                    <p class="mt-4 max-w-md text-indigo-100">
                        Crie chatbots, envie seus documentos e publique um widget no seu site em poucos minutos.
                    </p>
                </div>
            </aside>

            <main class="flex items-center justify-center px-6 py-12 sm:px-12 lg:col-span-7 xl:col-span-6">
                <div class="w-full max-w-md">
                    <a href="{{ url('/') }}" class="mb-8 block text-lg font-semibold text-indigo-700 lg:hidden">{{ config('app.name') }}</a>
                    @yield('content')
                </div>
            </main>
        </section>
    </body>
</html>
