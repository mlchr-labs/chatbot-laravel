@props(['color' => 'gray'])

@php
    $classes = match ($color) {
        'green' => 'bg-green-100 text-green-700',
        'red' => 'bg-red-100 text-red-700',
        'yellow' => 'bg-yellow-100 text-yellow-800',
        'indigo' => 'bg-indigo-100 text-indigo-700',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap', $classes]) }}>
    {{ $slot }}
</span>
