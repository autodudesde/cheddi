import { ll } from '@autodudes/cheddi/i18n.js';

/**
 * Placeholders shown only until `/cheddi/turn/progress` reports the tool that is actually running.
 * They say that something is happening and nothing more: guessing what the editor meant from
 * keywords in their own sentence was tried here and is the same mistake the backend navigation made.
 */
export const THINKING_PHASE_INTERVAL_MS = 4000;

export function defaultThinkingPhases() {
    return [
        ll('cheddi.thinking.default'),
        ll('cheddi.thinking.moment'),
        ll('cheddi.thinking.almost'),
    ];
}
