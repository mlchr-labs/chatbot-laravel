@props(['title', 'description' => null])

<div {{ $attributes->class('rounded-lg border border-dashed border-gray-300 bg-white px-6 py-12 text-center') }}>
    <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-indigo-100 text-indigo-700">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
    </div>
    <h2 class="mt-4 text-lg font-semibold text-gray-900">{{ $title }}</h2>
    @if ($description)
        <p class="mt-2 text-sm text-gray-600">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-6">{{ $action }}</div>
    @endisset
</div>
