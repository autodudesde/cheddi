
export function detectOpenRecord() {
    const candidates = [];
    try {
        candidates.push(window.location.href);
    } catch {
        // Location not readable
    }
    try {
        for (const frame of document.querySelectorAll('iframe')) {
            try {
                const href = frame.contentWindow?.location?.href;
                if (href) {
                    candidates.push(href);
                }
            } catch {
                // Cross-origin or still loading
            }
        }
    } catch {
        // Frames not accessible
    }

    for (const url of candidates) {
        let decoded = url;
        try {
            decoded = decodeURIComponent(url);
        } catch {
            // Malformed escapes: the raw URL is used
        }
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
    } catch {
        // No content container
    }

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
            } catch {
                // Cross-origin or still loading
            }
        }
    } catch {
        // Frames not accessible
    }

    return null;
}

export function readTurnContext() {
    return detectOpenRecord();
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
