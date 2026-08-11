import { ll } from '@autodudes/cheddi/i18n.js';

export function mapServerError(chatErrorCode, fallback) {
    const key = chatErrorCode ? 'cheddi.error.' + chatErrorCode : 'cheddi.error.unknown';
    const generic = fallback || ll('cheddi.error.unknown', 'Unknown error.');
    return ll(key, generic);
}

export function friendlyToolLabel(name) {
    if (!name) {
        return ll('cheddi.tool.fallback', 'Action');
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
