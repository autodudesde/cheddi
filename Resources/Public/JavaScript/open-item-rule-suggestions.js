import Modal from '@typo3/backend/modal.js';
import MultiStepWizard from '@typo3/backend/multi-step-wizard.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import { ll } from '@autodudes/cheddi/i18n.js';
import { resetWizard, slideElement } from '@autodudes/cheddi/multi-step-wizard-setup.js';

function element(tag, className, text = null) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== null) {
        node.textContent = text;
    }
    return node;
}

function countLabel(key, count, args = {}) {
    return ll(count === 1 ? `${key}One` : key, { count, ...args });
}

export class OpenItemRuleSuggestions {
    constructor(apiClient, { onAdopted }) {
        this.api = apiClient;
        this.onAdopted = onAdopted;
        this.suggestions = null;
        this.selected = new Set();
    }

    async open() {
        this.suggestions = null;
        this.selected = new Set();
        await resetWizard();
        MultiStepWizard.addSlide('cheddi-suggest-analyse', ll('cheddi.openItemRule.suggest.title'), '', Severity.info, ll('cheddi.openItemRule.suggest.stepAnalyse'), (slide) => this.analyse(slideElement(slide)));
        MultiStepWizard.addSlide('cheddi-suggest-choose', ll('cheddi.openItemRule.suggest.title'), '', Severity.info, ll('cheddi.openItemRule.suggest.stepChoose'), (slide) => this.renderChoice(slideElement(slide)));
        await MultiStepWizard.addFinalProcessingSlide(() => this.adopt());
        // Core reads currentModal a frame before it sets it and then skips the first slide.
        Modal.currentModal ??= document.createElement('div');
        MultiStepWizard.show();
    }

    async analyse(container) {
        MultiStepWizard.lockNextStep();
        const status = element('div', 'cheddi-suggest__loading');
        status.setAttribute('role', 'status');
        const label = element('p', 'cheddi-suggest__loading-text', ll('cheddi.openItemRule.suggest.stepStandard'));
        const bar = element('div', 'cheddi-progress cheddi-progress--indeterminate');
        bar.appendChild(element('div', 'cheddi-progress__fill'));
        status.append(label, bar);
        container.replaceChildren(status);

        let result = null;
        try {
            result = await this.api.fetchOpenItemRuleSuggestions();
        } catch (e) {
            console.error('[ChEddi] could not load the suggested checks.', e);
        }
        if (result === null) {
            label.textContent = ll('cheddi.openItemRule.suggest.failed');
            bar.remove();
            return;
        }

        this.suggestions = { recommended: result.recommended, gaps: result.gaps, restorable: result.restorable };
        this.selected = new Set([...result.recommended, ...result.gaps].map((entry) => entry.id));
        MultiStepWizard.unlockNextStep();
        MultiStepWizard.triggerStepButton('next');
    }

    renderChoice(container) {
        const recommended = this.suggestions?.recommended ?? [];
        const gaps = this.suggestions?.gaps ?? [];
        const restorable = this.suggestions?.restorable ?? [];
        const root = element('div', 'cheddi-suggest');
        const all = [...recommended, ...gaps, ...restorable];

        const toggle = element('div', 'form-check cheddi-suggest__all');
        const toggleInput = element('input', 'form-check-input');
        toggleInput.type = 'checkbox';
        toggleInput.id = 'cheddi-suggest-all';
        toggleInput.checked = this.selected.size === all.length;
        const toggleLabel = element('label', 'form-check-label', ll('cheddi.openItemRule.suggest.all'));
        toggleLabel.htmlFor = toggleInput.id;
        toggle.append(toggleInput, toggleLabel);
        if (all.length > 0) {
            root.appendChild(toggle);
        }

        const inputs = [];
        const group = (title, entries, countText, emptyText = null) => {
            if (entries.length === 0 && emptyText === null) {
                return;
            }
            root.appendChild(element('h3', 'cheddi-suggest__group', title));
            if (entries.length === 0) {
                root.appendChild(element('p', 'cheddi-suggest__status', emptyText));
                return;
            }
            for (const entry of entries) {
                const row = element('div', 'form-check cheddi-suggest__row');
                const input = element('input', 'form-check-input');
                input.type = 'checkbox';
                input.id = `cheddi-suggest-${entry.id.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
                input.dataset.cheddiSuggestion = entry.id;
                input.checked = this.selected.has(entry.id);
                input.addEventListener('change', () => {
                    if (input.checked) {
                        this.selected.add(entry.id);
                    } else {
                        this.selected.delete(entry.id);
                    }
                    toggleInput.checked = this.selected.size === all.length;
                    this.syncNext();
                });
                inputs.push(input);
                const label = element('label', 'form-check-label cheddi-suggest__label');
                label.htmlFor = input.id;
                const text = element('span', 'cheddi-suggest__text');
                text.appendChild(element('span', 'cheddi-suggest__title', entry.title));
                if (entry.reason) {
                    text.appendChild(element('span', 'cheddi-suggest__reason', entry.reason));
                }
                label.append(text, element('span', 'badge badge-default cheddi-suggest__count', countText(entry)));
                row.append(input, label);
                root.appendChild(row);
            }
        };
        group(ll('cheddi.openItemRule.suggest.recommended'), recommended, (entry) => countLabel('cheddi.openItemRule.suggest.matches', entry.count));
        group(ll('cheddi.openItemRule.suggest.gaps'), gaps, (entry) => ll('cheddi.openItemRule.suggest.proposalCount', { count: entry.count, total: entry.total }), ll('cheddi.openItemRule.suggest.gapsNone'));
        group(ll('cheddi.openItemRule.suggest.restorable'), restorable, (entry) => countLabel('cheddi.openItemRule.suggest.matches', entry.count));

        toggleInput.addEventListener('change', () => {
            this.selected = toggleInput.checked ? new Set(all.map((entry) => entry.id)) : new Set();
            for (const input of inputs) {
                input.checked = toggleInput.checked;
            }
            this.syncNext();
        });

        container.replaceChildren(root);
        this.syncNext();
    }

    syncNext() {
        const next = this.selected.size === 0 ? MultiStepWizard.lockNextStep() : MultiStepWizard.unlockNextStep();
        next.text(countLabel('cheddi.openItemRule.suggest.adopt', this.selected.size));
    }

    async adopt() {
        let result = { ok: false, payload: null };
        try {
            result = await this.api.adoptOpenItemRules([...this.selected]);
        } catch (e) {
            console.error('[ChEddi] could not create the suggested checks.', e);
        }
        MultiStepWizard.dismiss();
        if (!result.ok) {
            Notification.error(ll('cheddi.openItemRule.ui.actionFailed'), '');
            return;
        }
        Notification.success(countLabel('cheddi.openItemRule.suggest.adopted', result.payload?.adopted ?? 0), '');
        this.onAdopted();
    }
}
