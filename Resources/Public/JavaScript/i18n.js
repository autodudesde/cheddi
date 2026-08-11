
export function ll(key, fallback = '', args = {}) {
    let value = (typeof TYPO3 !== 'undefined' && TYPO3.lang && TYPO3.lang[key]) ? TYPO3.lang[key] : fallback;
    for (const [token, replacement] of Object.entries(args)) {
        value = value.replaceAll('{' + token + '}', String(replacement));
    }
    return value;
}
