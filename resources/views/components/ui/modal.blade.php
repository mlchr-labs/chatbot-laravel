@props(['name', 'title'])

{{-- Open with: @click="$dispatch('open-modal', 'modal-name')" --}}
<div
    x-data="{ open: false }"
    x-cloak
    x-show="open"
    @open-modal.window="if ($event.detail === @js($name)) open = true"
    @keydown.escape.window="open = false"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $name }}-title"
>
    <div class="absolute inset-0 bg-gray-900/50" x-show="open" x-transition.opacity @click="open = false"></div>

    <div x-show="open" x-transition class="relative w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h2 id="{{ $name }}-title" class="text-lg font-semibold text-gray-900">{{ $title }}</h2>
        <div class="mt-2 text-sm text-gray-600">{{ $slot }}</div>
        <div class="mt-6 flex justify-end gap-3">
            <x-ui.button type="button" variant="secondary" @click="open = false">Cancelar</x-ui.button>
            {{ $footer ?? '' }}
        </div>
    </div>
</div>
