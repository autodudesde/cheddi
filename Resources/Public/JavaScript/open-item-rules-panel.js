import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { ll } from '@autodudes/cheddi/i18n.js';
import { loadingIndicator } from '@autodudes/cheddi/loading-indicator.js';
import { OpenItemRuleEditor } from '@autodudes/cheddi/open-item-rule-editor.js';
import { OpenItemRuleSuggestions } from '@autodudes/cheddi/open-item-rule-suggestions.js';
import { OpenItemRuleWizard } from '@autodudes/cheddi/open-item-rule-wizard.js';

const ORIGIN_LABELS = {
    standard: 'cheddi.openItemRule.origin.standard',
    suggested: 'cheddi.openItemRule.origin.suggested',
    custom: 'cheddi.openItemRule.origin.custom',
};

function element(tag, className, text = null) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== null) {
        node.textContent = text;
    }
    return node;
}

export class OpenItemRulesPanel {
    constructor(apiClient, elements, { onChanged, onCloseAll }) {
        this.api = apiClient;
        this.elements = elements;
        this.onChanged = onChanged;
        this.closedGroups = new Set();
        this.isAdmin = false;
        this.editor = new OpenItemRuleEditor(apiClient, () => this.changed());
        this.suggestions = new OpenItemRuleSuggestions(apiClient, { onAdopted: () => this.changed() });
        this.wizard = new OpenItemRuleWizard(apiClient, { onCreated: () => this.changed(), onExpert: () => this.editor.open() });
        this.elements.backOpenItemRulesButton.addEventListener('click', () => this.close());
        this.elements.closeOpenItemRulesButton.addEventListener('click', () => {
            this.close();
            onCloseAll();
        });
        this.elements.newOpenItemRuleButton.addEventListener('click', () => this.suggestions.open());
        this.elements.customOpenItemRuleButton.addEventListener('click', () => this.wizard.open(this.isAdmin));
    }

    async open() {
        this.elements.openItemRulesPanel.hidden = false;
        this.elements.openItemRulesList.replaceChildren(loadingIndicator(ll('cheddi.openItemRule.ui.loading')));
        await this.refresh();
    }

    close() {
        this.elements.openItemRulesPanel.hidden = true;
    }

    changed() {
        this.refresh();
        this.onChanged();
    }

    async refresh() {
        let payload = null;
        try {
            payload = await this.api.fetchOpenItemRules();
        } catch (e) {
            console.error('[ChEddi] could not load the checks.', e);
        }

        this.isAdmin = payload?.isAdmin === true;
        const list = this.elements.openItemRulesList;
        list.replaceChildren();
        if (payload === null) {
            list.appendChild(element('li', 'cheddi__open-items-empty', ll('cheddi.openItemRule.ui.loadFailed')));
            return;
        }
        if (payload.rules.length === 0) {
            list.appendChild(element('li', 'cheddi__open-items-empty', ll('cheddi.openItemRule.ui.empty')));
            return;
        }
        const groups = new Map();
        for (const rule of payload.rules) {
            if (!groups.has(rule.group)) {
                groups.set(rule.group, []);
            }
            groups.get(rule.group).push(rule);
        }
        for (const [label, rules] of groups) {
            list.appendChild(this.makeGroup(label, rules));
        }
    }

    makeGroup(label, rules) {
        const group = element('li', 'cheddi__open-item-group');
        const details = document.createElement('details');
        details.dataset.cheddiOpenItemRuleGroup = label;
        details.open = !this.closedGroups.has(label);
        details.addEventListener('toggle', () => {
            if (details.open) {
                this.closedGroups.delete(label);
            } else {
                this.closedGroups.add(label);
            }
        });
        const summary = element('summary', 'cheddi__open-item-group-summary');
        summary.append(element('span', 'cheddi__open-item-group-title', label), element('span', 'badge badge-default', String(rules.length)));
        const rows = element('ul', 'list-group cheddi__open-item-group-list');
        for (const rule of rules) {
            rows.appendChild(this.makeRow(rule));
        }
        details.append(summary, rows);
        group.appendChild(details);
        return group;
    }

    makeRow(rule) {
        const applicable = rule.applicable !== false;
        const row = element('li', `list-group-item cheddi__open-item-rule${rule.active && applicable ? '' : ' cheddi__open-item-rule--inactive'}`);
        row.dataset.cheddiOpenItemRule = String(rule.uid);

        row.appendChild(element('strong', 'cheddi__open-item-rule-title', rule.title || ll('cheddi.openItemRule.ui.untitled')));

        const origin = ll(ORIGIN_LABELS[rule.origin] ?? ORIGIN_LABELS.custom);
        const meta = element('div', 'cheddi__open-item-rule-meta', rule.editable ? [origin, rule.summary, rule.scope.label].join(' · ') : (applicable ? `${origin} · ${ll('cheddi.openItemRule.ui.foreign')}` : origin));
        meta.dataset.cheddiOpenItemRuleOrigin = rule.origin;
        row.appendChild(meta);

        if (!applicable && rule.requirement) {
            const requirement = element('div', 'cheddi__open-item-rule-meta', rule.requirement);
            requirement.dataset.cheddiOpenItemRuleRequirement = '';
            row.appendChild(requirement);
        }

        if (rule.editable && rule.problem) {
            const problem = element('div', 'cheddi__open-item-rule-problem', rule.problem);
            problem.dataset.cheddiOpenItemRuleProblem = '';
            row.appendChild(problem);
        }

        const actions = element('div', 'cheddi__open-item-rule-actions');
        if (rule.editable || !applicable) {
            actions.appendChild(this.makeToggle(rule));
        }
        if (rule.matches !== null) {
            const matches = element('span', 'badge badge-default', ll(rule.matches === 1 ? 'cheddi.openItemRule.ui.matchesOne' : 'cheddi.openItemRule.ui.matches', { count: rule.matches }));
            matches.dataset.cheddiOpenItemRuleMatches = '';
            actions.appendChild(matches);
        }
        if (rule.editable) {
            const buttons = element('span', 'cheddi__open-item-rule-buttons');
            if (this.isAdmin) {
                buttons.appendChild(this.makeIconButton('actions-pencil', ll('cheddi.openItemRule.ui.edit'), 'cheddiOpenItemRuleEdit', () => this.editor.open(rule)));
            }
            buttons.appendChild(this.makeIconButton('actions-delete', ll('cheddi.openItemRule.ui.delete'), 'cheddiOpenItemRuleDelete', () => this.confirmDelete(rule)));
            actions.appendChild(buttons);
        }
        if (actions.childElementCount > 0) {
            row.appendChild(actions);
        }

        return row;
    }

    makeToggle(rule) {
        const wrapper = element('div', 'form-check form-switch cheddi__open-item-rule-toggle');
        const toggle = element('input', 'form-check-input');
        toggle.type = 'checkbox';
        toggle.setAttribute('role', 'switch');
        toggle.checked = rule.active;
        toggle.id = `cheddi-open-item-rule-active-${rule.uid}`;
        toggle.dataset.cheddiOpenItemRuleToggle = '';
        toggle.setAttribute('aria-label', ll('cheddi.openItemRule.ui.activeFor', { title: rule.title }));
        toggle.disabled = rule.applicable === false;
        toggle.addEventListener('change', () => this.setActive(rule, toggle));
        wrapper.appendChild(toggle);
        return wrapper;
    }

    makeIconButton(icon, label, dataKey, onClick) {
        const button = element('button', 'btn btn-default btn-sm cheddi__header-button');
        button.type = 'button';
        button.dataset[dataKey] = '';
        button.title = label;
        button.setAttribute('aria-label', label);
        button.innerHTML = `<typo3-backend-icon identifier="${icon}" size="small" aria-hidden="true"></typo3-backend-icon>`;
        button.addEventListener('click', onClick);
        return button;
    }

    async setActive(rule, toggle) {
        toggle.disabled = true;
        if (await this.succeeded(this.api.toggleOpenItemRule(rule.uid, toggle.checked))) {
            this.changed();
            return;
        }
        toggle.checked = rule.active;
        toggle.disabled = false;
    }

    confirmDelete(rule) {
        const confirmation = Modal.confirm(
            ll('cheddi.openItemRule.ui.deleteTitle'),
            ll('cheddi.openItemRule.ui.deleteText', { title: rule.title || ll('cheddi.openItemRule.ui.untitled') }),
            SeverityEnum.warning,
        );
        confirmation.addEventListener('confirm.button.ok', async () => {
            confirmation.hideModal();
            if (await this.succeeded(this.api.deleteOpenItemRule(rule.uid))) {
                this.changed();
            }
        });
        confirmation.addEventListener('confirm.button.cancel', () => confirmation.hideModal());
    }

    async succeeded(request) {
        try {
            if ((await request).ok) {
                return true;
            }
        } catch (e) {
            console.error('[ChEddi] could not change a check.', e);
        }
        Notification.error(ll('cheddi.openItemRule.ui.actionFailed'), '');
        return false;
    }
}
