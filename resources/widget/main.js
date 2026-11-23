import css from './widget.css?inline';
import { createApi, ApiError } from './api.js';
import { createSession } from './session.js';
import { buildUi } from './ui.js';

// Capturar antes de qualquer codigo assincrono: currentScript e null depois.
const script = document.currentScript || document.querySelector('script[data-chatbot]');

(function init() {
    if (!script) return;
    const publicKey = script.dataset.chatbot;
    if (!publicKey || document.getElementById('cb-widget-root')) return;

    let apiBase;
    try {
        apiBase = new URL(script.src).origin;
    } catch {
        return;
    }

    const REPLY_TIMEOUT_MS = 90000;
    const api = createApi(apiBase);

    let ui = null;
    let connected = false;
    let connecting = null;
    let pendingOpen = false;
    let replyTimer = 0;
    let config = null;

    function endReply() {
        clearTimeout(replyTimer);
        ui.setBusy(false);
        if (ui) ui.focusInput();
    }

    const session = createSession({
        api,
        publicKey,
        handlers: {
            onStarted: () => ui.startReply(),
            onToken: (delta) => ui.appendToken(delta),
            onCompleted: (e) => {
                ui.finishReply(e?.content);
                endReply();
            },
            onFailed: (message) => {
                ui.abortReply();
                ui.showError(message || 'Não foi possível gerar a resposta.');
                endReply();
            },
        },
    });

    function ensureConnected() {
        if (connected) return Promise.resolve();
        if (!connecting) {
            ui.setBusy(true);
            connecting = session
                .connect()
                .then((messages) => {
                    connected = true;
                    ui.clearMessages();
                    if (messages.length === 0 && config.welcome_message) ui.addMessage('assistant', config.welcome_message);
                    for (const m of messages) ui.addMessage(m.role === 'user' ? 'user' : 'assistant', m.content);
                    ui.showError('');
                })
                .catch((err) => {
                    ui.showError(err instanceof ApiError ? err.message : 'Não foi possível conectar ao chat.');
                    session.disconnect();
                    throw err;
                })
                .finally(() => {
                    connecting = null;
                    ui.setBusy(false);
                });
        }
        return connecting;
    }

    async function send(content) {
        ui.showError('');
        try {
            await ensureConnected();
        } catch {
            return;
        }
        ui.addMessage('user', content);
        ui.setBusy(true);
        try {
            try {
                await api.send(session.ulid, await session.validToken(), content);
            } catch (err) {
                if (!(err instanceof ApiError) || err.status !== 401) throw err;
                await session.refresh();
                await api.send(session.ulid, await session.validToken(), content);
            }
            replyTimer = setTimeout(() => {
                ui.abortReply();
                ui.showError('A resposta demorou demais. Tente novamente.');
                ui.setBusy(false);
            }, REPLY_TIMEOUT_MS);
        } catch (err) {
            ui.showError(err instanceof ApiError ? err.message : 'Não foi possível enviar a mensagem.');
            ui.setBusy(false);
        }
    }

    function mount() {
        const host = document.createElement('div');
        host.id = 'cb-widget-root';
        // Shadow "open": facilita depuracao e testes; a isolacao de CSS e a mesma.
        // Nao ha segredo no DOM do widget (token fica em closure), entao "closed" nao ganharia seguranca.
        const shadow = host.attachShadow({ mode: 'open' });
        const style = document.createElement('style');
        style.textContent = css;
        shadow.appendChild(style);

        ui = buildUi({
            name: config.name,
            theme: config.theme && typeof config.theme === 'object' ? config.theme : {},
            onSend: send,
            onOpen: () => {
                ensureConnected().catch(() => {});
            },
            onClose: () => {},
        });
        shadow.appendChild(ui.root);
        document.body.appendChild(host);

        window.ChatbotWidget = Object.freeze({
            open: () => ui.open(),
            close: () => ui.close(),
        });
        if (pendingOpen) ui.open();
    }

    function start() {
        api.config(publicKey)
            .then((data) => {
                config = data;
                mount();
            })
            .catch(() => {
                /* chatbot inativo, dominio nao permitido ou offline: widget nao aparece */
            });
    }

    if (document.body) start();
    else document.addEventListener('DOMContentLoaded', start, { once: true });
})();
