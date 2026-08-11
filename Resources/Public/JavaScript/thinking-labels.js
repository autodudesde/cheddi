import { ll } from '@autodudes/cheddi/i18n.js';

export const THINKING_PHASE_INTERVAL_MS = 4000;

export function defaultThinkingPhases() {
    return [
        ll('cheddi.thinking.default', 'ChEddi is thinking'),
        ll('cheddi.thinking.moment', 'One moment …'),
        ll('cheddi.thinking.almost', 'Almost done …'),
    ];
}

const THINKING_INTENTS = [
    { verb: 'ChEddi is translating', key: 'cheddi.thinking.intent.translate', keywords: ['übersetz', 'translat', 'sprache', 'language', 'locali'] },
    { verb: 'ChEddi is creating metadata', key: 'cheddi.thinking.intent.metadata', keywords: ['seo', 'metadaten', 'metadata'] },
    { verb: 'ChEddi is writing content', key: 'cheddi.thinking.intent.write', keywords: ['erzeug', 'generier', 'schreib', 'landingpage', 'optimier'] },
    { verb: 'ChEddi is handling media', key: 'cheddi.thinking.intent.media', keywords: ['bild', 'image', 'foto', 'grafik', 'medien'] },
    { verb: 'ChEddi is editing content', key: 'cheddi.thinking.intent.edit', keywords: ['lösch', 'entfern', 'verschieb', 'kopier', 'seitenbaum'] },
];

export function intentThinkingLabel(text) {
    const lower = String(text || '').toLowerCase();
    for (const intent of THINKING_INTENTS) {
        if (intent.keywords.some((keyword) => lower.includes(keyword))) {
            return ll(intent.key, intent.verb);
        }
    }
    return ll('cheddi.thinking.default', 'ChEddi is thinking');
}
