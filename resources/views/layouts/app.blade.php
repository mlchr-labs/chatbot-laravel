@php
    $nav = [
        ['label' => 'Dashboard', 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard'),
            'icon' => 'M2.25 12l8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
        ['label' => 'Chatbots', 'href' => route('chatbots.index'), 'active' => request()->routeIs('chatbots.*'),
            'icon' => 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM12 20.25c4.97 0 9-3.694 9-8.25S16.97 3.75 12 3.75 3 7.444 3 12c0 1.6.5 3.09 1.36 4.35L3.75 20.25l4.2-.84A9.9 9.9 0 0 0 12 20.25Z'],
    ];
    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@hasSection('title')@yield('title') | @endif{{ config('app.name') }}</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans text-gray-900 antialiased" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
        {{-- Mobile overlay --}}
        <div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden" @click="sidebarOpen = false"></div>

        {{-- Sidebar --}}
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col justify-between border-e border-gray-200 bg-white transition-transform lg:translate-x-0"
            :class="{ 'translate-x-0': sidebarOpen }"
            aria-label="Navegação principal"
        >
            <div class="px-4 py-6">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 px-2 text-lg font-semibold text-indigo-700">
                    <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM12 20.25c4.97 0 9-3.694 9-8.25S16.97 3.75 12 3.75 3 7.444 3 12c0 1.6.5 3.09 1.36 4.35L3.75 20.25l4.2-.84A9.9 9.9 0 0 0 12 20.25Z" />
                    </svg>
                    {{ config('app.name') }}
                </a>

                <nav class="mt-8 space-y-1">
                    @foreach ($nav as $item)
                        <a
                            href="{{ $item['href'] }}"
                            @if ($item['active']) aria-current="page" @endif
                            @class([
                                'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                                'bg-indigo-50 text-indigo-700' => $item['active'],
                                'text-gray-600 hover:bg-gray-100 hover:text-gray-900' => ! $item['active'],
                            ])
                        >
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
            </div>

            <div class="border-t border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700" aria-hidden="true">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </span>
                    <div class="min-w-0 text-sm">
                        <p class="truncate font-medium text-gray-900">{{ $user->name }}</p>
                        <p class="truncate text-gray-500">{{ $user->email }}</p>
                    </div>
                </div>
            </div>
        </aside>

        <div class="lg:pl-64">
            {{-- Header --}}
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-gray-200 bg-white px-4 sm:px-6">
                <button type="button" class="-ms-1 rounded-md p-2 text-gray-600 hover:bg-gray-100 lg:hidden" @click="sidebarOpen = true" aria-label="Abrir menu">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    </svg>
                </button>

                <h1 class="hidden text-lg font-semibold text-gray-900 lg:block">@yield('heading')</h1>

                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
                        @click="open = ! open"
                        :aria-expanded="open"
                        aria-haspopup="menu"
                    >
                        <span class="flex size-8 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700" aria-hidden="true">
                            {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                        </span>
                        <span class="hidden sm:inline">{{ $user->name }}</span>
                        <svg class="size-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <div x-cloak x-show="open" x-transition class="absolute end-0 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg" role="menu">
                        <div class="border-b border-gray-100 px-4 py-2 text-xs text-gray-500">{{ $user->email }}</div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full px-4 py-2 text-start text-sm text-gray-700 hover:bg-gray-50" role="menuitem">Sair</button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="p-4 sm:p-6 lg:p-8">
                @if (session('status'))
                    <x-ui.alert type="success" class="mb-6">{{ session('status') }}</x-ui.alert>
                @endif

                @yield('content')
            </main>
        </div>
    </body>
</html>
