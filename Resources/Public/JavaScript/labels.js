import { ll, llOr } from '@autodudes/cheddi/i18n.js';

export function mapServerError(chatErrorCode, fallback) {
    const generic = fallback || ll('cheddi.error.unknown');
    if (!chatErrorCode) {
        return generic;
    }
    return llOr('cheddi.error.' + chatErrorCode, generic);
}

export function friendlyToolLabel(name) {
    if (!name) {
        return ll('cheddi.tool.fallback');
    }
    const label = (typeof TYPO3 !== 'undefined' && TYPO3.lang)
        ? TYPO3.lang['cheddi.tool.' + name]
        : undefined;
    if (label) {
        return label;
    }

    const spaced = String(name)
        .replace(/([a-z\d])([A-Z])/g, '$1 $2')
        .replace(/[_-]+/g, ' ')
        .toLowerCase()
        .trim();
    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}
