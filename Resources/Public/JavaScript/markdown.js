import { marked } from 'marked';
import DOMPurify from 'dompurify';

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (character) => ESCAPES[character]);
}

marked.use({
    breaks: true,
    gfm: true,
    renderer: {
        html({ text }) {
            return escapeHtml(text);
        },
        image({ text }) {
            return escapeHtml(text);
        },
    },
});

export function renderMarkdown(text) {
    const rawHtml = marked.parse(text ?? '', { async: false });
    return DOMPurify.sanitize(rawHtml, {
        USE_PROFILES: { html: true },
        FORBID_TAGS: ['script', 'iframe', 'style', 'object', 'embed', 'form', 'input', 'img', 'button', 'textarea', 'select', 'svg', 'math'],
        FORBID_ATTR: ['style', 'onerror', 'onclick', 'onload', 'onmouseover', 'onfocus', 'onblur', 'onsubmit'],
        ALLOW_DATA_ATTR: false,
        ALLOWED_URI_REGEXP: /^(?:https?|mailto):/i,
    });
}
