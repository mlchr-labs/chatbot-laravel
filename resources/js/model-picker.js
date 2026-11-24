// Provider, key and model fields of the chatbot form.
//
// The model is always a <select>. Without a key of its own it lists the platform models embedded in the
// page. With one (typed now, or saved for the same provider) the list is fetched by the server and the key
// only travels as a POST body, never in the URL, the HTML or the console. "Outro modelo…" swaps the select
// for a text input that is submitted in the same `model` field, so the backend validation does not change.
const CUSTOM = '__custom__';
const DEBOUNCE_MS = 400;
const MIN_KEY_LENGTH = 20;

export default ({ catalog, labels, provider, model, savedProvider, hasSavedKey, removeKey, chatbot, url, csrf }) => ({
    catalog,
    provider,
    selected: model,
    customModel: '',
    apiKey: '',
    removeKey,
    loaded: null, // null until the provider list of the current key/provider arrived
    loading: false,
    error: null,
    blocked: false, // rate limited: the manual reload waits
    timer: null,
    lastSignature: null,

    init() {
        this.normalize();

        this.$watch('provider', () => {
            this.loaded = null;
            this.error = null;
            this.lastSignature = null;

            // A model of the previous provider means nothing here, the default of the new one replaces it.
            if (!this.custom) this.selected = '';
            this.normalize();
            this.schedule();
        });

        this.$watch('ownKey', (own) => {
            this.error = null;
            if (own) {
                this.schedule();
            } else {
                this.loaded = null;
                this.lastSignature = null;
                this.normalize();
            }
        });

        this.$watch('selected', (value, previous) => {
            if (value !== CUSTOM) return;

            // Starts the free text from the model that was selected, it is usually a small edit.
            if (previous && previous !== CUSTOM) this.customModel = previous;
            this.$nextTick(() => this.$refs.customModel?.focus());
        });

        // Saved key and same provider: the list comes by itself (the backend cache absorbs the repetition).
        this.$nextTick(() => this.maybeLoad());
    },

    get custom() {
        return this.selected === CUSTOM;
    },

    // Same rule the backend applies: a key typed now, or a saved one that is not being removed.
    get ownKey() {
        return this.apiKey.trim() !== '' || (hasSavedKey && !this.removeKey);
    },

    // The saved key is never reused for another provider.
    get usesSavedKey() {
        return this.apiKey.trim() === '' && hasSavedKey && !this.removeKey && this.provider === savedProvider;
    },

    get canLoad() {
        const typed = this.apiKey.trim();

        return typed !== '' ? typed.length >= MIN_KEY_LENGTH : this.usesSavedKey;
    },

    get providerLabel() {
        return labels[this.provider] ?? this.provider;
    },

    get defaultModel() {
        return this.catalog[this.provider]?.default ?? '';
    },

    get platformModels() {
        return this.catalog[this.provider]?.models ?? [];
    },

    // Whether the list on screen is the authoritative one for the current setup.
    get listKnown() {
        return this.ownKey ? this.loaded !== null : true;
    },

    // Until the provider answers, the platform models stand in as suggestions.
    get baseList() {
        return this.ownKey && this.loaded !== null ? this.loaded : this.platformModels;
    },

    // The current model stays selectable even when it is not in the list, so it is never swapped silently.
    get options() {
        const list = this.baseList.map((id) => ({ value: id, label: id }));

        if (this.selected !== '' && !this.custom && !this.baseList.includes(this.selected)) {
            list.unshift({ value: this.selected, label: this.listKnown ? `${this.selected} (indisponível)` : this.selected });
        }

        return list;
    },

    get unavailable() {
        return this.listKnown && !this.custom && this.selected !== '' && !this.baseList.includes(this.selected);
    },

    get unavailableMessage() {
        return `"${this.selected}" não está disponível no ${this.providerLabel}. Escolha outro modelo.`;
    },

    get status() {
        if (this.loading) return 'Carregando modelos…';
        if (this.loaded !== null && this.ownKey && !this.error) {
            return this.loaded.length === 1 ? '1 modelo da sua chave' : `${this.loaded.length} modelos da sua chave`;
        }

        return null;
    },

    get hint() {
        if (!this.ownKey || this.loading || this.error || this.loaded !== null) return null;

        return this.usesSavedKey || this.apiKey.trim().length >= MIN_KEY_LENGTH
            ? null
            : hasSavedKey && this.apiKey.trim() === ''
              ? 'A chave salva é de outro provedor. Cole a chave deste provedor para listar os modelos.'
              : 'Cole a chave completa para listar os modelos.';
    },

    // Empty model: the provider default if it is in the list, otherwise the first of the list.
    normalize() {
        if (this.custom || this.selected !== '' || !this.listKnown) return;

        this.selected = this.baseList.includes(this.defaultModel) ? this.defaultModel : (this.baseList[0] ?? '');
    },

    useList() {
        this.selected = this.baseList.includes(this.customModel) ? this.customModel : '';
        this.normalize();
    },

    signature() {
        return `${this.provider}|${this.apiKey.trim() || 'saved'}`;
    },

    // Paste and blur on the key field.
    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.maybeLoad(), DEBOUNCE_MS);
    },

    // Never repeats a request for the same provider and key, whatever the outcome was.
    maybeLoad() {
        if (!this.canLoad || this.loading || this.signature() === this.lastSignature) return;

        this.load();
    },

    // Manual link: the same request again, unless the server asked to wait.
    reload() {
        if (!this.canLoad || this.loading || this.blocked) return;

        this.load();
    },

    async load() {
        const requested = this.signature();
        const typed = this.apiKey.trim();

        this.lastSignature = requested;
        this.loading = true;
        this.error = null;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    provider: this.provider,
                    api_key: typed || null,
                    chatbot: typed ? null : chatbot,
                }),
            });

            let payload = null;
            try {
                payload = await response.json();
            } catch (e) {
                // Non JSON body (proxy error page, for instance): handled below.
            }

            // Provider or key changed while the request was in flight: the answer is stale.
            if (requested !== this.signature()) return;

            if (response.ok && Array.isArray(payload?.models)) {
                this.loaded = payload.models;
                this.normalize();

                if (payload.models.length === 0) {
                    this.error = 'O provedor não devolveu nenhum modelo de chat. Use "Outro modelo…" para digitar o nome.';
                }

                return;
            }

            this.loaded = null;
            this.error = payload?.message ?? 'Não foi possível carregar os modelos. Use "Outro modelo…" para digitar o nome.';

            if (response.status === 429) this.block(Number(response.headers.get('Retry-After')));
        } catch (e) {
            if (requested !== this.signature()) return;

            this.loaded = null;
            this.error = 'Falha de rede ao carregar os modelos. Use "Outro modelo…" para digitar o nome.';
        } finally {
            this.loading = false;

            // Provider or key moved on while this one was running: the new setup still needs its list.
            if (requested !== this.signature()) this.maybeLoad();
        }
    },

    // Rate limited: no retries on its own and the reload link waits for the window the server announced.
    block(seconds) {
        this.blocked = true;
        setTimeout(() => (this.blocked = false), (seconds > 0 ? seconds : 60) * 1000);
    },
});
