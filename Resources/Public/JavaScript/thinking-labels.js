import { ll } from '@autodudes/cheddi/i18n.js';

export const THINKING_PHASE_INTERVAL_MS = 4000;

export function defaultThinkingPhases() {
    return [
        ll('cheddi.thinking.default'),
        ll('cheddi.thinking.moment'),
        ll('cheddi.thinking.almost'),
    ];
}
