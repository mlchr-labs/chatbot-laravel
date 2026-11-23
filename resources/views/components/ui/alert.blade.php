@props(['type' => 'info'])

@php
    $classes = match ($type) {
        'success' => 'border-green-200 bg-green-50 text-green-800',
        'error' => 'border-red-200 bg-red-50 text-red-800',
        'warning' => 'border-yellow-200 bg-yellow-50 text-yellow-800',
        default => 'border-indigo-200 bg-indigo-50 text-indigo-800',
    };
@endphp

<div role="{{ $type === 'error' ? 'alert' : 'status' }}" {{ $attributes->class(['rounded-md border p-4 text-sm', $classes]) }}>
    {{ $slot }}
</div>
