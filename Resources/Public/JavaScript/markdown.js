import { marked } from 'marked';
import DOMPurify from 'dompurify';

marked.use({ breaks: true, gfm: true });

function escapeRawHtmlOutsideCode(text) {
    if (text === '' || text == null) {
        return '';
    }

    const segments = text.split(/(```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\n]+`)/);
    return segments
        .map((segment, index) => (
            index % 2 === 0
                ? segment.replace(/</g, '&lt;').replace(/>/g, '&gt;')
                : segment
        ))
        .join('');
}

export function renderMarkdown(text) {
    const escaped = escapeRawHtmlOutsideCode(text ?? '');
    const rawHtml = marked.parse(escaped, { async: false });
    return DOMPurify.sanitize(rawHtml, {
        USE_PROFILES: { html: true },
        FORBID_TAGS: ['script', 'iframe', 'style', 'object', 'embed', 'form', 'input'],
        FORBID_ATTR: ['onerror', 'onclick', 'onload', 'onmouseover', 'onfocus', 'onblur', 'onsubmit'],
        ALLOW_DATA_ATTR: false,
        ADD_ATTR: ['target', 'rel'],
        ALLOWED_URI_REGEXP: /^(?:https?|mailto):/i,
    });
}
