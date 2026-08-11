
export function detectOpenRecord() {
    const candidates = [];
    try {
        candidates.push(window.location.href);
    } catch (e) { console.debug('[ChEddi] could not read window.location.href.', e); }
    try {
        for (const frame of document.querySelectorAll('iframe')) {
            try {
                const href = frame.contentWindow?.location?.href;
                if (href) {
                    candidates.push(href);
                }
            } catch (e) {
                console.debug('[ChEddi] iframe location not readable (cross-origin/loading).', e);
            }
        }
    } catch (e) { console.debug('[ChEddi] could not scan iframes for the open record.', e); }

    for (const url of candidates) {
        let decoded = url;
        try {
            decoded = decodeURIComponent(url);
        } catch (e) { console.debug('[ChEddi] malformed URL escapes, using the raw URL.', e); }
        const match = /edit\[([a-z0-9_]+)\]\[(\d+)\]=edit/i.exec(decoded);
        if (match) {
            return { recordTable: match[1], recordUid: Number(match[2]) };
        }
    }
    return {};
}

export function currentPageId() {
    try {
        const id = top?.TYPO3?.Backend?.ContentContainer?.getIdFromUrl?.();
        if (Number.isInteger(id) && id > 0) {
            return id;
        }
    } catch (e) { console.debug('[ChEddi] ContentContainer page id not readable.', e); }

    const params = new URLSearchParams(window.location.search);
    const raw = params.get('id') ?? '';
    if (/^\d+$/.test(raw)) {
        return Number(raw);
    }

    try {
        for (const frame of document.querySelectorAll('iframe')) {
            try {
                const search = frame.contentWindow?.location?.search;
                const frameId = search ? new URLSearchParams(search).get('id') : null;
                if (frameId && /^\d+$/.test(frameId)) {
                    return Number(frameId);
                }
            } catch (e) {
                console.debug('[ChEddi] iframe location not readable (cross-origin/loading).', e);
            }
        }
    } catch (e) { console.debug('[ChEddi] could not scan iframes for the page id.', e); }

    return null;
}

export function readBackendContext() {
    const moduleName = (typeof TYPO3 !== 'undefined' && TYPO3.ModuleMenu)
        ? (document.querySelector('[data-module-name]')?.dataset?.moduleName ?? '')
        : '';
    return {
        pageId: currentPageId(),
        module: moduleName,
        ...detectOpenRecord(),
    };
}
