<div {{ $attributes->class('overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-xs') }}>
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        @isset($head)
            <thead class="bg-gray-50 text-start text-xs font-semibold tracking-wide text-gray-600 uppercase">
                <tr class="*:px-4 *:py-3 *:text-start">
                    {{ $head }}
                </tr>
            </thead>
        @endisset
        <tbody class="divide-y divide-gray-100 text-gray-700 *:*:px-4 *:*:py-3">
            {{ $slot }}
        </tbody>
    </table>
</div>
