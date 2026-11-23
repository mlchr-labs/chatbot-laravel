import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

function store(publicKey) {
    const key = (name) => `cbw:${publicKey}:${name}`;
    return {
        get(name) {
            try {
                return localStorage.getItem(key(name));
            } catch {
                return null;
            }
        },
        set(name, value) {
            try {
                localStorage.setItem(key(name), value);
            } catch {
                /* storage bloqueado: segue sem persistir */
            }
        },
        remove(name) {
            try {
                localStorage.removeItem(key(name));
            } catch {
                /* noop */
            }
        },
    };
}

/**
 * Gerencia sessao (token em memoria), persistencia e conexao Echo.
 * Nada e aberto antes de connect().
 */
export function createSession({ api, publicKey, handlers }) {
    const storage = store(publicKey);
    let token = null;
    let expiresAt = 0;
    let authEndpoint = null;
    let ulid = null;
    let echo = null;
    let channelName = null;
    let refreshing = null;

    const tokenValid = () => token !== null && Date.now() < expiresAt - 15000;

    async function refresh() {
        if (refreshing) return refreshing;
        refreshing = (async () => {
            const payload = { public_key: publicKey };
            const savedUlid = ulid ?? storage.get('conversation_ulid');
            const visitor = storage.get('visitor_id');
            if (savedUlid) payload.conversation_ulid = savedUlid;
            if (visitor) payload.visitor_id = visitor;

            const data = await api.session(payload);
            token = data.widget_token;
            expiresAt = Date.parse(data.expires_at) || Date.now() + 5 * 60 * 1000;
            authEndpoint = data.auth_endpoint;
            ulid = data.conversation_ulid;
            storage.set('visitor_id', data.visitor_id);
            storage.set('conversation_ulid', data.conversation_ulid);
            return data;
        })();
        try {
            return await refreshing;
        } finally {
            refreshing = null;
        }
    }

    async function validToken() {
        if (!tokenValid()) await refresh();
        return token;
    }

    function connectEcho(data) {
        const { reverb } = data;
        const tls = reverb.scheme === 'https';
        channelName = data.channel;

        echo = new Echo({
            broadcaster: 'reverb',
            Pusher, // evita depender de window.Pusher (sem globais)
            key: reverb.key,
            wsHost: reverb.host,
            wsPort: reverb.port,
            wssPort: reverb.port,
            forceTLS: tls,
            enabledTransports: ['ws', 'wss'],
            cluster: 'mt1', // exigido pelo tipo do pusher-js, ignorado com wsHost
            // Autorizacao cross-origin com Bearer, sem cookies/CSRF.
            authorizer: (channel) => ({
                authorize: (socketId, callback) => {
                    validToken()
                        .then((t) => api.authorize(authEndpoint, t, socketId, channel.name))
                        .then((res) => callback(null, res))
                        .catch((err) => callback(err instanceof Error ? err : new Error('auth failed'), null));
                },
            }),
        });

        return new Promise((resolve, reject) => {
            const channel = echo
                .private(channelName.replace(/^private-/, ''))
                .listen('.reply.started', () => handlers.onStarted())
                .listen('.reply.token', (e) => handlers.onToken(e.delta ?? ''))
                .listen('.reply.completed', (e) => handlers.onCompleted(e))
                .listen('.reply.failed', (e) => handlers.onFailed(e?.message));
            channel.subscribed(() => resolve());
            channel.error(() => reject(new Error('Falha ao conectar no canal.')));
        });
    }

    return {
        /** Abre sessao + websocket. Retorna as mensagens do historico. */
        async connect() {
            const data = await refresh();
            await connectEcho(data);
            return data.messages ?? [];
        },
        validToken,
        refresh,
        get ulid() {
            return ulid;
        },
        hasSavedConversation: () => storage.get('conversation_ulid') !== null,
        forgetConversation: () => {
            storage.remove('conversation_ulid');
            ulid = null;
        },
        disconnect() {
            if (echo) echo.disconnect();
            echo = null;
        },
    };
}
