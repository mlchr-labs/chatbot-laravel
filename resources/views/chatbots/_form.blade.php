@php
    $providerOptions = collect($providers)->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
    $currentProvider = $chatbot->provider instanceof \BackedEnum ? $chatbot->provider->value : $chatbot->provider;

    $domainsOld = old('allowed_domains');
    $domainsValue = is_array($domainsOld)
        ? implode("\n", $domainsOld)
        : ($domainsOld ?? implode("\n", $chatbot->allowed_domains ?? []));
@endphp

<div class="space-y-6">
    <div>
        <x-ui.label for="name">Nome</x-ui.label>
        <x-ui.input name="name" :value="$chatbot->name" required maxlength="120" />
    </div>

    <div>
        <x-ui.label for="system_prompt">Prompt do sistema</x-ui.label>
        <x-ui.textarea name="system_prompt" :value="$chatbot->system_prompt" rows="6" maxlength="8000" />
        <p class="mt-1 text-xs text-gray-500">Instruções de comportamento e tom do chatbot.</p>
    </div>

    <div class="grid gap-6 sm:grid-cols-2">
        <div>
            <x-ui.label for="provider">Provedor de LLM</x-ui.label>
            <x-ui.select name="provider" :options="$providerOptions" :selected="$currentProvider" required />
        </div>
        <div>
            <x-ui.label for="model">Modelo</x-ui.label>
            <x-ui.input name="model" :value="$chatbot->model" required maxlength="100" placeholder="gpt-4o-mini" />
        </div>
    </div>

    <div class="grid gap-6 sm:grid-cols-2">
        <div>
            <x-ui.label for="temperature">Temperatura (0 a 2)</x-ui.label>
            <x-ui.input name="temperature" type="number" step="0.01" min="0" max="2" :value="$chatbot->temperature" required />
        </div>
        <div>
            <x-ui.label for="api_key">Chave de API do provedor (opcional)</x-ui.label>
            <x-ui.input name="api_key" type="password" autocomplete="off" maxlength="500"
                :placeholder="$chatbot->hasApiKey() ? 'Chave configurada' : 'Usa a chave da plataforma se vazio'" />
            @if ($chatbot->hasApiKey())
                <p class="mt-1 text-xs text-gray-500">Deixe em branco para manter a chave atual.</p>
                <label class="mt-2 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="remove_api_key" value="1" @checked(old('remove_api_key')) class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600">
                    Remover a chave atual
                </label>
            @endif
        </div>
    </div>

    <div>
        <x-ui.label for="welcome_message">Mensagem de boas-vindas</x-ui.label>
        <x-ui.textarea name="welcome_message" :value="$chatbot->welcome_message" rows="2" maxlength="500" />
    </div>

    <div>
        <x-ui.label for="allowed_domains">Domínios permitidos</x-ui.label>
        <x-ui.textarea name="allowed_domains" :value="$domainsValue" rows="4" placeholder="meusite.com.br&#10;www.meusite.com.br" />
        @foreach ($errors->get('allowed_domains.*') as $messages)
            @foreach ($messages as $message)
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @endforeach
        @endforeach
        <p class="mt-1 text-xs text-gray-500">Um host por linha, sem https:// nem caminho. Porta é opcional (ex: localhost:3000).</p>
    </div>

    <div>
        <input type="hidden" name="is_active" value="0">
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $chatbot->is_active)) class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600">
            Chatbot ativo
        </label>
    </div>
</div>
