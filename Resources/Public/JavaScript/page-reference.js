import Notification from '@typo3/backend/notification.js';
import { currentPageId } from '@autodudes/cheddi/backend-context.js';
import { ll } from '@autodudes/cheddi/i18n.js';

const titles = new Map();

async function readPageTitle(api, pageId, fresh) {
    if (!fresh && titles.has(pageId)) {
        return titles.get(pageId);
    }
    try {
        const title = await api.fetchPageTitle(pageId);
        if (title !== null) {
            titles.set(pageId, title);
        }
        return title;
    } catch {
        return null;
    }
}

export async function describeCurrentPage(api, fresh = false) {
    const pageId = currentPageId();
    const title = pageId === null ? null : await readPageTitle(api, pageId, fresh);
    return title === null ? null : { pageId, title };
}

export async function insertCurrentPage(api, textarea) {
    const page = await describeCurrentPage(api, true);
    if (page === null) {
        Notification.warning(ll('cheddi.ui.noCurrentPage'), '');
        return;
    }
    if (textarea.disabled) {
        return;
    }

    const reference = ll('cheddi.ui.pageReference', { title: page.title, uid: page.pageId });
    const before = textarea.value.slice(0, textarea.selectionStart);
    const after = textarea.value.slice(textarea.selectionEnd);
    const lead = before === '' || /\s$/.test(before) ? '' : ' ';
    const trail = after === '' || /^\s/.test(after) ? '' : ' ';
    const caret = before.length + lead.length + reference.length + trail.length;

    textarea.value = before + lead + reference + trail + after;
    textarea.focus();
    textarea.setSelectionRange(caret, caret);
    textarea.dispatchEvent(new Event('input'));
}
