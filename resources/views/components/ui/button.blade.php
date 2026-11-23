@props(['variant' => 'primary', 'href' => null, 'type' => 'submit'])

@php
    $classes = 'inline-flex items-center justify-center gap-2 rounded-md px-5 py-2.5 text-sm font-medium transition focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:cursor-not-allowed disabled:opacity-60 '.match ($variant) {
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-indigo-600',
        'danger' => 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-600',
        'ghost' => 'text-gray-700 hover:bg-gray-100 focus:ring-indigo-600',
        default => 'bg-indigo-600 text-white hover:bg-indigo-700 focus:ring-indigo-600',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
