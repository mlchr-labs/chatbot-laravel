import { renderMarkdownToFragment } from './sanitize.js';

function el(tag, className, attrs = {}) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
    return node;
}

const COLOR = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

export function buildUi({ name, theme, onSend, onOpen, onClose }) {
    const primary = typeof theme.primary_color === 'string' && COLOR.test(theme.primary_color) ? theme.primary_color : '#4f46e5';
    const left = theme.position === 'left';

    const root = el('div', 'fixed bottom-4 z-[2147483000] ' + (left ? 'left-4' : 'right-4'));
    root.style.setProperty('--cb-primary', primary);

    const launcher = el(
        'button',
        'flex h-14 w-14 items-center justify-center rounded-full bg-[var(--cb-primary)] text-white shadow-lg cursor-pointer border-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--cb-primary)]',
        { type: 'button', 'aria-label': 'Abrir chat', 'aria-expanded': 'false', 'aria-controls': 'cb-panel' },
    );
    launcher.appendChild(icon('M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.6-.8L3 21l1.9-5.4A8.4 8.4 0 1 1 21 11.5z'));

    const panel = el(
        'section',
        'hidden flex-col mb-3 w-[22rem] max-w-[calc(100vw-2rem)] h-[32rem] max-h-[calc(100vh-6rem)] overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/10',
        { id: 'cb-panel', role: 'dialog', 'aria-label': 'Chat com ' + name },
    );

    const header = el('header', 'flex items-center justify-between bg-[var(--cb-primary)] px-4 py-3 text-white');
    const title = el('h2', 'm-0 text-base font-semibold truncate');
    title.textContent = name;
    const closeBtn = el('button', 'cursor-pointer rounded border-0 bg-transparent p-1 text-white hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-white', {
        type: 'button',
        'aria-label': 'Fechar chat',
    });
    closeBtn.appendChild(icon('M6 6l12 12M18 6L6 18'));
    header.append(title, closeBtn);

    const list = el('div', 'flex-1 overflow-y-auto bg-gray-50 p-3 space-y-2', {
        role: 'log',
        'aria-live': 'polite',
        'aria-label': 'Mensagens',
        tabindex: '0',
    });

    const errorBox = el('p', 'hidden m-0 bg-red-50 px-3 py-2 text-sm text-red-700', { role: 'alert' });

    const form = el('form', 'flex items-end gap-2 border-t border-gray-200 bg-white p-2');
    const input = el(
        'textarea',
        'flex-1 resize-none rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-2 focus:outline-[var(--cb-primary)] max-h-28',
        { rows: '1', maxlength: '2000', placeholder: 'Digite sua mensagem', 'aria-label': 'Mensagem' },
    );
    const sendBtn = el(
        'button',
        'cursor-pointer rounded-lg border-0 bg-[var(--cb-primary)] px-3 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--cb-primary)]',
        { type: 'submit', 'aria-label': 'Enviar mensagem' },
    );
    sendBtn.textContent = 'Enviar';
    form.append(input, sendBtn);

    panel.append(header, list, errorBox, form);
    root.append(panel, launcher);

    let isOpen = false;
    let busy = false;
    let typingEl = null;
    let streamEl = null;
    let streamText = '';
    let raf = 0;

    const scrollDown = () => {
        list.scrollTop = list.scrollHeight;
    };

    function bubble(role) {
        const mine = role === 'user';
        const b = el(
            'div',
            'max-w-[85%] rounded-2xl px-3 py-2 break-words ' +
                (mine ? 'ml-auto bg-[var(--cb-primary)] text-white whitespace-pre-wrap' : 'mr-auto bg-white text-gray-900 ring-1 ring-black/5 cb-md'),
        );
        list.appendChild(b);
        return b;
    }

    function addMessage(role, content) {
        const b = bubble(role);
        if (role === 'user') b.textContent = content; // nunca HTML
        else b.replaceChildren(renderMarkdownToFragment(content));
        scrollDown();
        return b;
    }

    function setBusy(value) {
        busy = value;
        sendBtn.disabled = value;
        sendBtn.setAttribute('aria-disabled', String(value));
        list.setAttribute('aria-busy', String(value));
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.toggle('hidden', !message);
    }

    function setOpen(value) {
        isOpen = value;
        panel.classList.toggle('hidden', !value);
        panel.classList.toggle('flex', value);
        launcher.setAttribute('aria-expanded', String(value));
        launcher.setAttribute('aria-label', value ? 'Fechar chat' : 'Abrir chat');
        if (value) {
            input.focus();
            scrollDown();
        } else {
            launcher.focus();
        }
    }

    function open() {
        if (isOpen) return;
        setOpen(true);
        onOpen();
    }

    function close() {
        if (!isOpen) return;
        setOpen(false);
        onClose();
    }

    function submit() {
        const content = input.value.trim();
        if (!content || busy) return;
        input.value = '';
        onSend(content);
    }

    launcher.addEventListener('click', () => (isOpen ? close() : open()));
    closeBtn.addEventListener('click', close);
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        submit();
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            submit();
        }
    });
    panel.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            e.stopPropagation();
            close();
        }
    });

    function showTyping() {
        if (typingEl) return;
        typingEl = el('div', 'mr-auto flex gap-1 rounded-2xl bg-white px-3 py-3 ring-1 ring-black/5', { 'aria-label': 'Digitando' });
        for (let i = 0; i < 3; i++) typingEl.appendChild(el('span', 'cb-dot h-2 w-2 rounded-full bg-gray-400'));
        list.appendChild(typingEl);
        scrollDown();
    }

    function hideTyping() {
        typingEl?.remove();
        typingEl = null;
    }

    function paintStream() {
        raf = 0;
        if (!streamEl) return;
        streamEl.replaceChildren(renderMarkdownToFragment(streamText));
        scrollDown();
    }

    return {
        root,
        open,
        close,
        addMessage,
        setBusy,
        showError,
        get isBusy() {
            return busy;
        },
        clearMessages: () => list.replaceChildren(),
        focusInput: () => input.focus(),
        startReply() {
            showTyping();
        },
        appendToken(delta) {
            hideTyping();
            if (!streamEl) {
                streamEl = bubble('assistant');
                streamText = '';
            }
            streamText += delta;
            if (!raf) raf = requestAnimationFrame(paintStream);
        },
        finishReply(finalText) {
            hideTyping();
            if (raf) cancelAnimationFrame(raf);
            raf = 0;
            if (typeof finalText === 'string') {
                if (!streamEl) streamEl = bubble('assistant');
                streamEl.replaceChildren(renderMarkdownToFragment(finalText)); // autoritativo
            }
            streamEl = null;
            streamText = '';
            scrollDown();
        },
        abortReply() {
            hideTyping();
            if (raf) cancelAnimationFrame(raf);
            raf = 0;
            streamEl = null;
            streamText = '';
        },
    };
}

function icon(path) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '24');
    svg.setAttribute('height', '24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    const p = document.createElementNS(ns, 'path');
    p.setAttribute('d', path);
    svg.appendChild(p);
    return svg;
}
