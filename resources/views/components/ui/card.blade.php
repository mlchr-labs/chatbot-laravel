<div {{ $attributes->class('rounded-lg border border-gray-200 bg-white shadow-xs') }}>
    @isset($header)
        <div class="border-b border-gray-200 px-6 py-4">
            <h2 class="text-base font-semibold text-gray-900">{{ $header }}</h2>
        </div>
    @endisset

    <div class="p-6">
        {{ $slot }}
    </div>
</div>
