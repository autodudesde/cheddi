import { ll } from '@autodudes/cheddi/i18n.js';
import { friendlyToolLabel } from '@autodudes/cheddi/labels.js';
import { THINKING_PHASE_INTERVAL_MS, defaultThinkingPhases } from '@autodudes/cheddi/thinking-labels.js';

export class ThinkingIndicator {
    constructor(container) {
        this.container = container;
        this.el = null;
        this.labelEl = null;
        this.timer = null;
        this.phases = [];
        this.stepSuffix = 0;
        this.phaseIndex = 0;
    }

    show(label, step = 0, total = 0) {
        this.stopSequence();
        this.renderLabel(label, step, total);
    }

    showSequence(phases, step = 0) {
        this.stopSequence();
        const sequence = Array.isArray(phases) && phases.length > 0 ? phases : defaultThinkingPhases();
        this.phases = sequence;
        this.stepSuffix = step;
        this.phaseIndex = 0;
        this.renderLabel(sequence[0], step);
        this.timer = window.setInterval(() => {
            if (this.phaseIndex >= this.phases.length - 1) {
                this.stopSequence();
                return;
            }
            this.phaseIndex += 1;
            this.renderLabel(this.phases[this.phaseIndex], this.stepSuffix);
        }, THINKING_PHASE_INTERVAL_MS);
    }

    stopSequence() {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    }

    updateFromToolCalls(toolCalls, step = 0) {
        const labels = (toolCalls ?? [])
            .map((call) => friendlyToolLabel(call?.name))
            .filter(Boolean);
        if (labels.length === 0) {
            this.show(ll('cheddi.thinking.default'), step);
            return;
        }
        const label = labels.length === 1
            ? labels[0]
            : `${labels[0]} (+${labels.length - 1})`;
        this.show(label, step);
    }

    hide() {
        this.stopSequence();
        if (this.el && this.el.parentNode) {
            this.el.parentNode.removeChild(this.el);
        }
    }

    keepAtBottom() {
        if (this.el && this.el.parentNode === this.container) {
            this.container.appendChild(this.el);
        }
    }

    renderLabel(label, step = 0, total = 0) {
        if (!this.el) {
            this.el = document.createElement('div');
            this.el.className = 'cheddi__thinking';
            this.el.setAttribute('aria-live', 'polite');
            this.el.innerHTML = `
                <span class="cheddi__thinking-label" data-cheddi-thinking-label></span>
                <span class="cheddi__thinking-dots" aria-hidden="true"><span></span><span></span><span></span></span>
            `;
            this.labelEl = this.el.querySelector('[data-cheddi-thinking-label]');
        }
        const base = label || ll('cheddi.thinking.default');

        if (total >= 2 && step >= 1) {
            this.labelEl.textContent = ll('cheddi.thinking.stepOf', { base, step, total });
        } else if (step >= 2) {
            this.labelEl.textContent = ll('cheddi.thinking.step', { base, step });
        } else {
            this.labelEl.textContent = base;
        }

        this.container.appendChild(this.el);
        this.container.scrollTop = this.container.scrollHeight;
    }
}
