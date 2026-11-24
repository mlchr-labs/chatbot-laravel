@php
    $providerOptions = collect($providers)->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
    $currentProvider = $chatbot->provider instanceof \BackedEnum ? $chatbot->provider->value : $chatbot->provider;

    $domainsOld = old('allowed_domains');
    $domainsValue = is_array($domainsOld)
        ? implode("\n", $domainsOld)
        : ($domainsOld ?? implode("\n", $chatbot->allowed_domains ?? []));

    $fieldClasses = 'mt-1 block w-full rounded-md shadow-xs sm:text-sm '.($errors->has('model')
        ? 'border-red-500 focus:border-red-600 focus:ring-red-600'
        : 'border-gray-300 focus:border-indigo-600 focus:ring-indigo-600');
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

    <div class="space-y-6" x-data="modelPicker({
        catalog: @js($modelCatalog),
        provider: @js(old('provider', $currentProvider)),
        model: @js((string) old('model', $chatbot->model)),
        savedProvider: @js($currentProvider),
        hasSavedKey: @js($chatbot->hasApiKey()),
        removeKey: @js((bool) old('remove_api_key')),
        chatbot: @js($chatbot->exists ? $chatbot->ulid : null),
        url: @js(route('chatbots.models')),
        csrf: @js(csrf_token()),
    })">
        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <x-ui.label for="provider">Provedor de LLM</x-ui.label>
                <x-ui.select name="provider" :options="$providerOptions" :selected="$currentProvider" required x-model="provider" />
            </div>
            <div>
                <x-ui.label for="model">Modelo</x-ui.label>

                {{-- Platform key: only the models the platform allows for the provider. --}}
                <template x-if="!ownKey">
                    <select name="model" id="model" required x-model="model" class="{{ $fieldClasses }}">
                        <template x-for="option in platformModels" :key="option">
                            <option :value="option" x-text="option" :selected="option === model"></option>
                        </template>
                    </select>
                </template>

                {{-- Own key: free text, with the provider list as suggestions once loaded. --}}
                <template x-if="ownKey">
                    <div>
                        <input type="text" name="model" id="model" required maxlength="100" autocomplete="off"
                            list="model-options" x-model="model" :placeholder="defaultModel" class="{{ $fieldClasses }}">
                        <datalist id="model-options">
                            <template x-for="option in loaded" :key="option">
                                <option :value="option"></option>
                            </template>
                        </datalist>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <x-ui.button type="button" variant="secondary" class="px-3! py-1.5!" x-on:click="load()" x-bind:disabled="loading || !canLoad">
                                <span x-text="loading ? 'Carregando...' : 'Carregar modelos'"></span>
                            </x-ui.button>
                            <span class="text-xs text-gray-500" x-show="loaded.length > 0" x-cloak x-text="loaded.length + ' modelos disponíveis'"></span>
                        </div>
                        <p class="mt-1 text-sm text-red-600" x-show="error" x-cloak x-text="error" role="alert"></p>
                        <p class="mt-1 text-xs text-gray-500" x-show="!canLoad" x-cloak>Digite a chave de API do provedor para listar os modelos, ou escreva o nome do modelo.</p>
                    </div>
                </template>

                @error('model')
                    <p id="model-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <x-ui.label for="temperature">Temperatura (0 a 2)</x-ui.label>
                <x-ui.input name="temperature" type="number" step="0.01" min="0" max="2" :value="$chatbot->temperature" required />
            </div>
            <div>
                <x-ui.label for="api_key">Chave de API do provedor (opcional)</x-ui.label>
                <x-ui.input name="api_key" type="password" autocomplete="off" maxlength="500" x-model="apiKey"
                    :placeholder="$chatbot->hasApiKey() ? 'Chave configurada' : 'Usa a chave da plataforma se vazio'" />
                @if ($chatbot->hasApiKey())
                    <p class="mt-1 text-xs text-gray-500">Deixe em branco para manter a chave atual.</p>
                    <label class="mt-2 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="remove_api_key" value="1" x-model="removeKey" @checked(old('remove_api_key')) class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600">
                        Remover a chave atual
                    </label>
                @endif
            </div>
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
