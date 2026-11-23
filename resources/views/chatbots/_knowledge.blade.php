<div
    x-data="knowledgeSources({
        sources: @js($sources),
        indexUrl: @js(route('chatbots.sources.index', $chatbot)),
        storeUrl: @js(route('chatbots.sources.store', $chatbot)),
        csrf: @js(csrf_token()),
    })"
    class="space-y-6"
>
    <x-ui.alert type="warning">
        O conteúdo enviado aqui é usado para responder visitantes públicos e pode ser exposto por eles nas conversas. Não envie dados confidenciais.
    </x-ui.alert>

    <x-ui.card>
        <x-slot:header>Enviar documentos</x-slot:header>

        <label
            for="knowledge-file-input-{{ $chatbot->ulid }}"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop($event)"
            :class="dragging ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300 bg-white hover:bg-gray-50'"
            class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-6 py-10 text-center transition"
        >
            <svg class="size-8 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
            <span class="mt-3 text-sm font-medium text-gray-900">Arraste os arquivos aqui ou clique para escolher</span>
            <span class="mt-1 text-xs text-gray-500">PDF, TXT, MD ou HTML, até 16 MB cada</span>
            <input
                id="knowledge-file-input-{{ $chatbot->ulid }}"
                type="file"
                multiple
                accept=".pdf,.txt,.md,.html"
                class="sr-only"
                @change="onPick($event)"
            >
        </label>

        <ul class="mt-4 space-y-2" x-show="uploads.length > 0" x-cloak>
            <template x-for="upload in uploads" :key="upload.id">
                <li class="rounded-md border border-gray-200 px-3 py-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="truncate font-medium text-gray-900" x-text="upload.name"></span>
                        <template x-if="upload.error">
                            <button type="button" class="text-xs text-gray-500 hover:text-gray-700" @click="dismiss(upload)">Dispensar</button>
                        </template>
                        <template x-if="!upload.error">
                            <span class="text-xs text-gray-500" x-text="upload.progress + '%'"></span>
                        </template>
                    </div>
                    <p x-show="upload.error" x-text="upload.error" class="mt-1 text-xs text-red-600" role="alert"></p>
                    <div x-show="!upload.error" class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full bg-indigo-600 transition-all" :style="`width: ${upload.progress}%`"></div>
                    </div>
                </li>
            </template>
        </ul>
    </x-ui.card>

    <div x-show="sources.length === 0" x-cloak>
        <x-ui.empty-state title="Nenhum documento ainda" description="Envie arquivos para o chatbot responder com base no conteúdo deles." />
    </div>

    <div x-show="sources.length > 0" x-cloak>
        <x-ui.table>
            <x-slot:head>
                <th>Nome</th>
                <th>Tamanho</th>
                <th>Status</th>
                <th>Trechos</th>
                <th>Enviado em</th>
                <th><span class="sr-only">Ações</span></th>
            </x-slot:head>
            <template x-for="source in sources" :key="source.ulid">
                <tr>
                    <td class="max-w-xs">
                        <span class="block truncate font-medium text-gray-900" x-text="source.name"></span>
                        <span x-show="source.error" x-text="source.error" class="mt-0.5 block text-xs text-red-600"></span>
                    </td>
                    <td class="whitespace-nowrap" x-text="formatSize(source.size)"></td>
                    <td>
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap"
                            :class="badgeClass(source.status)"
                            x-text="source.status_label"
                        ></span>
                    </td>
                    <td x-text="source.chunks_count"></td>
                    <td class="whitespace-nowrap" x-text="formatDate(source.created_at)"></td>
                    <td class="text-end">
                        <button type="button" class="text-sm font-medium text-red-600 hover:text-red-700" @click="askDelete(source)">Excluir</button>
                    </td>
                </tr>
            </template>
        </x-ui.table>
    </div>

    <x-ui.modal name="delete-source" title="Excluir documento">
        <span>Isso remove <strong x-text="toDelete?.name"></strong> e os trechos indexados dele. A ação não pode ser desfeita.</span>
        <x-slot:footer>
            <x-ui.button type="button" variant="danger" @click="confirmDelete(); open = false">Excluir</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
