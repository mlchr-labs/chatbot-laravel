@props(['name', 'value' => null, 'rows' => 4])

@php
    $hasError = $errors->has($name);
    $errorId = $name.'-error';
@endphp

<textarea
    name="{{ $name }}"
    id="{{ $attributes->get('id', $name) }}"
    rows="{{ $rows }}"
    @if ($hasError) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif
    {{ $attributes->except(['id'])->class([
        'mt-1 block w-full rounded-md shadow-xs sm:text-sm',
        'border-gray-300 focus:border-indigo-600 focus:ring-indigo-600' => ! $hasError,
        'border-red-500 focus:border-red-600 focus:ring-red-600' => $hasError,
    ]) }}
>{{ old($name, $value) }}</textarea>

@error($name)
    <p id="{{ $errorId }}" class="mt-1 text-sm text-red-600">{{ $message }}</p>
@enderror
