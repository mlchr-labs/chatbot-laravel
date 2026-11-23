import { marked } from 'marked';
import DOMPurify from 'dompurify';

const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'code', 'pre', 'ul', 'ol', 'li', 'a', 'blockquote'];
const ALLOWED_ATTR = ['href'];
const SAFE_HREF = /^(?:https?:|mailto:)/i;

// Instancia propria para nao mexer em hooks de outro DOMPurify na pagina.
const purify = DOMPurify(window);

purify.addHook('afterSanitizeAttributes', (node) => {
    if (node.nodeName !== 'A') return;
    const href = node.getAttribute('href');
    if (href === null || !SAFE_HREF.test(href.trim())) {
        node.removeAttribute('href');
        return;
    }
    node.setAttribute('target', '_blank');
    node.setAttribute('rel', 'noopener noreferrer nofollow');
});

/** Markdown -> HTML sanitizado (string). Funcao pura, sem tocar no DOM da pagina. */
export function renderMarkdownToHtml(markdown) {
    const raw = marked.parse(String(markdown ?? ''), { async: false, gfm: true, breaks: true });
    return purify.sanitize(raw, { ALLOWED_TAGS, ALLOWED_ATTR, ALLOWED_URI_REGEXP: SAFE_HREF });
}

/** Markdown -> DocumentFragment sanitizado, pronto para replaceChildren (sem innerHTML). */
export function renderMarkdownToFragment(markdown) {
    const raw = marked.parse(String(markdown ?? ''), { async: false, gfm: true, breaks: true });
    return purify.sanitize(raw, {
        ALLOWED_TAGS,
        ALLOWED_ATTR,
        ALLOWED_URI_REGEXP: SAFE_HREF,
        RETURN_DOM_FRAGMENT: true,
    });
}
