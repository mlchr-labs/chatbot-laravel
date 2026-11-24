@extends('layouts.app')

@section('title', $chatbot->name)
@section('heading', $chatbot->name)

@section('content')
    @php
        $tabs = ['config' => 'Configuração', 'knowledge' => 'Base de conhecimento', 'widget' => 'Widget', 'conversations' => 'Conversas'];
    @endphp

    <div
        x-data="{
            tab: 'config',
            tabs: @js(array_keys($tabs)),
            init() {
                const hash = window.location.hash.slice(1);
                if (this.tabs.includes(hash)) this.tab = hash;
            },
            select(name) { this.tab = name; history.replaceState(null, '', '#' + name); },
        }"
    >
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex gap-6 overflow-x-auto" aria-label="Seções do chatbot" role="tablist">
                @foreach ($tabs as $key => $label)
                    <button
                        type="button"
                        role="tab"
                        @click="select(@js($key))"
                        :aria-selected="tab === @js($key)"
                        :class="tab === @js($key) ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                        class="shrink-0 border-b-2 px-1 pb-3 text-sm font-medium"
                    >{{ $label }}</button>
                @endforeach
            </nav>
        </div>

        {{-- Configuração --}}
        <section x-show="tab === 'config'" class="mt-6 space-y-6" role="tabpanel">
            <x-ui.card>
                <x-slot:header>Configuração</x-slot:header>
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="font-medium text-gray-500">Status</dt>
                        <dd class="mt-1"><x-ui.badge :color="$chatbot->is_active ? 'green' : 'gray'">{{ $chatbot->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge></dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Provedor e modelo</dt>
                        <dd class="mt-1 text-gray-900">{{ $chatbot->provider->label() }} / {{ $chatbot->model }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Temperatura</dt>
                        <dd class="mt-1 text-gray-900">{{ $chatbot->temperature }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Chave de API própria</dt>
                        <dd class="mt-1 text-gray-900">{{ $chatbot->hasApiKey() ? 'Configurada' : 'Não configurada (usa a da plataforma)' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="font-medium text-gray-500">Mensagem de boas-vindas</dt>
                        <dd class="mt-1 whitespace-pre-wrap text-gray-900">{{ $chatbot->welcome_message ?: 'Nenhuma' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="font-medium text-gray-500">Prompt do sistema</dt>
                        <dd class="mt-1 whitespace-pre-wrap text-gray-900">{{ $chatbot->system_prompt ?: 'Nenhum' }}</dd>
                    </div>
                </dl>
                <div class="mt-6 flex flex-wrap gap-3">
                    <x-ui.button :href="route('chatbots.edit', $chatbot)">Editar</x-ui.button>
                    <x-ui.button type="button" variant="danger" @click="$dispatch('open-modal', 'delete-chatbot')">Excluir</x-ui.button>
                </div>
            </x-ui.card>
        </section>

        {{-- Base de conhecimento --}}
        <section x-show="tab === 'knowledge'" x-cloak class="mt-6" role="tabpanel">
            @include('chatbots._knowledge')
        </section>

        {{-- Widget --}}
        <section x-show="tab === 'widget'" x-cloak class="mt-6 space-y-6" role="tabpanel">
            <x-ui.card>
                <x-slot:header>Código de incorporação</x-slot:header>
                <p class="text-sm text-gray-600">Cole este snippet antes de <code>&lt;/body&gt;</code> no site onde o chatbot vai aparecer.</p>
                <div class="mt-4" x-data="{ snippet: @js($snippet), copied: false, copy() { navigator.clipboard.writeText(this.snippet).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); }); } }">
                    <pre class="overflow-x-auto rounded-md bg-gray-900 p-4 text-sm text-gray-100"><code>{{ $snippet }}</code></pre>
                    <div class="mt-3">
                        <x-ui.button type="button" variant="secondary" @click="copy()">
                            <span x-show="! copied">Copiar snippet</span>
                            <span x-show="copied" x-cloak>Copiado!</span>
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card>
                <x-slot:header>Domínios permitidos</x-slot:header>
                @if (empty($chatbot->allowed_domains))
                    <p class="text-sm text-gray-600">Nenhum domínio cadastrado: o widget fica bloqueado nos seus sites até você autorizar pelo menos um. A sandbox, quando ligada, funciona mesmo sem domínio. Adicione os sites autorizados na <a class="text-indigo-600 underline" href="{{ route('chatbots.edit', $chatbot) }}">edição do chatbot</a>.</p>
                @else
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($chatbot->allowed_domains as $domain)
                            <li><x-ui.badge color="indigo">{{ $domain }}</x-ui.badge></li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card>
                <x-slot:header>Sandbox</x-slot:header>
                <x-ui.alert type="warning">
                    Com a sandbox ligada, qualquer pessoa com o link pode conversar com o chatbot. Isso consome créditos do provedor e pode expor as respostas da base de conhecimento. Deixe ligada só enquanto estiver testando.
                </x-ui.alert>
                <p class="mt-4 text-sm text-gray-600">Uma página pública para testar o widget sem precisar de um site próprio.</p>
                <form method="POST" action="{{ route('chatbots.sandbox.toggle', $chatbot) }}" class="mt-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="sandbox_enabled" value="{{ $chatbot->sandbox_enabled ? 0 : 1 }}">
                    <x-ui.button variant="secondary">{{ $chatbot->sandbox_enabled ? 'Desligar sandbox' : 'Ligar sandbox' }}</x-ui.button>
                </form>
                @if ($chatbot->sandbox_enabled)
                    @php($sandboxUrl = route('sandbox.show', $chatbot->public_key))
                    <div class="mt-4 text-sm" x-data="{ url: @js($sandboxUrl), copied: false, copy() { navigator.clipboard.writeText(this.url).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); }); } }">
                        <code class="break-all rounded bg-gray-100 px-1.5 py-0.5">{{ $sandboxUrl }}</code>
                        <div class="mt-3 flex flex-wrap gap-3">
                            <x-ui.button :href="$sandboxUrl" target="_blank" rel="noopener noreferrer">Abrir sandbox</x-ui.button>
                            <x-ui.button type="button" variant="secondary" @click="copy()">
                                <span x-show="! copied">Copiar link</span>
                                <span x-show="copied" x-cloak>Copiado!</span>
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card>
                <x-slot:header>Chave pública</x-slot:header>
                <p class="text-sm text-gray-600">Identificador usado pelo widget: <code class="rounded bg-gray-100 px-1.5 py-0.5">{{ $chatbot->public_key }}</code></p>
                <p class="mt-2 text-sm text-gray-600">Gerar uma nova chave invalida o snippet atual. Os sites que usam o widget precisam ser atualizados.</p>
                <form method="POST" action="{{ route('chatbots.rotate-key', $chatbot) }}" class="mt-4">
                    @csrf
                    <x-ui.button variant="secondary">Gerar nova chave</x-ui.button>
                </form>
            </x-ui.card>
        </section>

        {{-- Conversas --}}
        <section x-show="tab === 'conversations'" x-cloak class="mt-6" role="tabpanel">
            @if ($conversations->isEmpty())
                <x-ui.empty-state title="Nenhuma conversa ainda" description="As conversas dos visitantes aparecem aqui assim que o widget estiver no ar." />
            @else
                <x-ui.table>
                    <x-slot:head>
                        <th>Visitante</th>
                        <th>Origem</th>
                        <th>Mensagens</th>
                        <th>Última atividade</th>
                        <th><span class="sr-only">Ações</span></th>
                    </x-slot:head>
                    @foreach ($conversations as $conversation)
                        <tr>
                            <td class="font-mono text-xs">{{ \Illuminate\Support\Str::limit($conversation->visitor_id ?? '-', 12) }}</td>
                            <td>{{ $conversation->origin ?? '-' }}</td>
                            <td>{{ $conversation->messages_count }}</td>
                            <td>{{ $conversation->last_message_at?->diffForHumans() ?? '-' }}</td>
                            <td class="text-end"><a class="font-medium text-indigo-600 hover:text-indigo-700" href="{{ route('chatbots.conversations.show', [$chatbot, $conversation]) }}">Ver</a></td>
                        </tr>
                    @endforeach
                </x-ui.table>
                <p class="mt-4 text-sm"><a class="text-indigo-600 hover:text-indigo-700" href="{{ route('chatbots.conversations.index', $chatbot) }}">Ver todas as conversas</a></p>
            @endif
        </section>
    </div>

    <x-ui.modal name="delete-chatbot" title="Excluir chatbot">
        Isso apaga o chatbot, a base de conhecimento e todas as conversas. A ação não pode ser desfeita.
        <x-slot:footer>
            <form method="POST" action="{{ route('chatbots.destroy', $chatbot) }}">
                @csrf
                @method('DELETE')
                <x-ui.button variant="danger">Excluir definitivamente</x-ui.button>
            </form>
        </x-slot:footer>
    </x-ui.modal>
@endsection
