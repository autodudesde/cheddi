
/**
 * A literal key never carries a fallback: `LanguageLabelContractTest` asserts it exists in
 * locallang.xlf, which is a cheaper guarantee than a second copy of the sentence in every call.
 * Use llOr() where the key is composed at runtime and may legitimately be absent.
 */
export function ll(key, args = {}) {
    return interpolate(lookup(key) ?? '', args);
}

export function llOr(key, fallback, args = {}) {
    return interpolate(lookup(key) ?? fallback, args);
}

function lookup(key) {
    if (typeof TYPO3 === 'undefined' || !TYPO3.lang) {
        return null;
    }
    const value = TYPO3.lang[key];
    return typeof value === 'string' && value !== '' ? value : null;
}

function interpolate(value, args) {
    let result = String(value);
    for (const [token, replacement] of Object.entries(args ?? {})) {
        result = result.replaceAll('{' + token + '}', String(replacement));
    }
    return result;
}
