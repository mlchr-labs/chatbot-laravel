// Model field of the chatbot form. Without a key of its own the model comes from the platform list
// embedded in the page. With one, the list is fetched by the server (the key is only a POST body).
// The text input stays usable whatever happens: the server validates the model again on save.
export default ({ catalog, provider, model, savedProvider, hasSavedKey, removeKey, chatbot, url, csrf }) => ({
    catalog,
    provider,
    model,
    apiKey: '',
    removeKey,
    loaded: [],
    loading: false,
    error: null,

    init() {
        this.syncModel();

        this.$watch('provider', () => {
            this.loaded = [];
            this.error = null;
            this.syncModel(true);
        });

        this.$watch('apiKey', () => {
            this.loaded = [];
            this.error = null;
        });

        // Back to the platform key: the select only has allowed models.
        this.$watch('ownKey', () => this.syncModel());
    },

    // Same rule the backend applies: a key typed now, or a saved one that is not being removed.
    get ownKey() {
        return this.apiKey.trim() !== '' || (hasSavedKey && !this.removeKey);
    },

    get defaultModel() {
        return this.catalog[this.provider]?.default ?? '';
    },

    get platformModels() {
        return this.catalog[this.provider]?.models ?? [];
    },

    // Whether the saved key can be reused for the selected provider (it is never sent to another one).
    get canLoad() {
        return this.apiKey.trim() !== '' || (hasSavedKey && !this.removeKey && this.provider === savedProvider);
    },

    // Keeps the field coherent with the provider: a value that came from the platform lists is replaced
    // by the new default, a model the user typed is left alone.
    syncModel(providerChanged = false) {
        const known = Object.values(this.catalog).some((entry) => entry.models.includes(this.model));
        const valid = this.platformModels.includes(this.model);

        if (!this.model || (providerChanged && known && !valid) || (!this.ownKey && !valid)) {
            this.model = this.defaultModel;
        }
    },

    async load() {
        if (this.loading) return;

        const requested = this.provider;
        const typed = this.apiKey.trim();

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
                    provider: requested,
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

            // The user changed provider while the request was in flight.
            if (requested !== this.provider) return;

            if (response.ok && Array.isArray(payload?.models)) {
                this.loaded = payload.models;
                if (payload.models.length === 0) {
                    this.error = 'O provedor não devolveu nenhum modelo. Digite o nome do modelo.';
                }
                return;
            }

            this.error = payload?.message ?? 'Não foi possível carregar os modelos. Digite o nome do modelo.';
        } catch (e) {
            this.error = 'Falha de rede ao carregar os modelos. Digite o nome do modelo.';
        } finally {
            this.loading = false;
        }
    },
});
