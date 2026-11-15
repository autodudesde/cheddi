import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { currentPageId } from '@autodudes/cheddi/backend-context.js';
import { ll } from '@autodudes/cheddi/i18n.js';

const PREVIEW_DELAY_MS = 350;
const AGE = 'older_than_days';
const RELATED = 'has_no_related';
const WITH_VALUE = ['contains', 'not_contains', 'equals', 'not_equals', 'greater', 'less'];
const WITH_DAYS = [AGE, 'date_within_days'];
const SEVERITIES = ['notice', 'warning', 'error'];

function element(tag, className, text = null) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== null) {
        node.textContent = text;
    }
    return node;
}

function select(options, value, onChange, label) {
    const control = element('select', 'form-select');
    control.setAttribute('aria-label', label);
    for (const option of options) {
        const entry = document.createElement('option');
        entry.value = String(option.value);
        entry.textContent = option.label;
        entry.selected = String(option.value) === String(value);
        if (option.title) {
            entry.title = option.title;
        }
        control.appendChild(entry);
    }
    control.addEventListener('change', () => onChange(control.value));
    return control;
}

function labelled(labelText, control, dataKey) {
    const wrapper = element('div', 'mb-3');
    wrapper.dataset[dataKey] = '';
    const label = element('label', 'form-label', labelText);
    label.htmlFor = `cheddi-rule-${dataKey}`;
    control.id = label.htmlFor;
    wrapper.append(label, control);
    return wrapper;
}

function comparisonLabel(comparison) {
    return ll(`cheddi.openItemRule.ui.comparison.${comparison}`);
}

export class OpenItemRuleEditor {
    constructor(api, onSaved) {
        this.api = api;
        this.onSaved = onSaved;
        this.options = null;
        this.fields = new Map();
        this.draft = null;
        this.modal = null;
        this.previewRun = 0;
        this.previewTimer = null;
        this.opening = false;
    }

    async open(rule = null) {
        if (this.opening) {
            return;
        }
        this.opening = true;
        try {
            const options = await this.api.fetchOpenItemRuleOptions({ pageId: currentPageId() ?? 0 });
            if (options === null) {
                Notification.error(ll('cheddi.openItemRule.ui.loadFailed'), '');
                return;
            }
            this.options = options;
            this.draft = rule === null ? this.emptyDraft() : { ...structuredClone(rule.values), uid: rule.uid };
            this.summary = rule?.summary ?? '';
            if (rule?.scope && !options.scopes.some((scope) => scope.value === rule.scope.value)) {
                options.scopes.push(rule.scope);
            }
            if (!this.draft.source) {
                await this.fieldChoices();
            }
            this.showModal(rule === null);
        } catch (e) {
            console.error('[ChEddi] could not open the form of a check.', e);
            Notification.error(ll('cheddi.openItemRule.ui.loadFailed'), '');
        } finally {
            this.opening = false;
        }
    }

    emptyDraft() {
        return {
            title: '',
            source_table: this.options.tables.find((table) => table.value === 'tt_content')?.value ?? this.options.tables[0]?.value ?? '',
            match_mode: 'all',
            preset: '',
            conditions: [{ field_name: '', comparison: '', compare_value: '', related_table: '', related_field: '' }],
            root_page: this.options.scopes[0]?.value ?? 0,
            severity: 'notice',
        };
    }

    showModal(isNew) {
        this.modal = Modal.advanced({
            title: ll(isNew ? 'cheddi.openItemRule.ui.custom' : 'cheddi.openItemRule.ui.edit'),
            content: '',
            size: Modal.sizes.large,
            buttons: [
                { text: ll('cheddi.openItemRule.ui.cancel'), btnClass: 'btn-default', name: 'cancel', trigger: () => this.modal?.hideModal() },
                { text: ll('cheddi.openItemRule.ui.save'), btnClass: 'btn-primary', name: 'save', trigger: () => this.save() },
            ],
        });
        this.modal.addEventListener('typo3-modal-shown', () => this.render(), { once: true });
        this.modal.addEventListener('typo3-modal-hidden', () => {
            window.clearTimeout(this.previewTimer);
            this.previewRun += 1;
            this.modal = null;
        });
    }

    body() {
        return this.modal?.querySelector('.modal-body') ?? null;
    }

    saveButton() {
        return this.modal?.querySelector('button[name="save"]') ?? null;
    }

    async fieldsOf(table) {
        if (!this.fields.has(table)) {
            let answer = null;
            try {
                answer = await this.api.fetchOpenItemRuleOptions({ table });
            } catch (e) {
                console.error('[ChEddi] could not load the fields of a check.', e);
            }
            this.fields.set(table, Array.isArray(answer?.fields) ? answer.fields : []);
        }
        return this.fields.get(table);
    }

    async fieldChoices() {
        const fields = await this.fieldsOf(this.draft.source_table);
        const table = this.options.tables.find((entry) => entry.value === this.draft.source_table);
        return table?.supportsAge
            ? [{ value: '', label: ll('cheddi.openItemRule.ui.lastChange'), comparisons: [AGE] }, ...fields]
            : fields;
    }

    async render() {
        const body = this.body();
        if (body === null) {
            return;
        }
        if (this.draft.source) {
            this.renderSourceRule(body);
            return;
        }
        const choices = await this.fieldChoices();
        for (const condition of this.draft.conditions) {
            this.ensureValidCondition(condition, choices);
        }

        const form = element('div', 'cheddi-rule-editor');
        const head = element('div', 'cheddi-rule-editor__head');
        head.append(
            labelled(ll('cheddi.openItemRule.ui.table'), select(
                this.options.tables.map((table) => ({ value: table.value, label: table.label, title: table.value })),
                this.draft.source_table,
                (value) => {
                    this.draft.source_table = value;
                    this.draft.conditions = [{ field_name: '', comparison: '', compare_value: '', related_table: '', related_field: '' }];
                    this.render();
                },
                ll('cheddi.openItemRule.ui.table'),
            ), 'cheddiRuleTable'),
            labelled(ll('cheddi.openItemRule.ui.scope'), select(
                this.options.scopes,
                this.draft.root_page,
                (value) => {
                    this.draft.root_page = Number(value);
                    this.schedulePreview();
                },
                ll('cheddi.openItemRule.ui.scope'),
            ), 'cheddiRuleScope'),
        );

        const conditions = element('section', 'cheddi-rule-editor__conditions');
        conditions.dataset.cheddiRuleConditions = '';
        const title = element('div', 'cheddi-rule-editor__section-head');
        title.appendChild(element('span', 'form-label mb-0', ll('cheddi.openItemRule.ui.conditionsTitle')));
        if (this.draft.conditions.length > 1) {
            const match = select(
                [
                    { value: 'all', label: ll('cheddi.openItemRule.ui.matchAll') },
                    { value: 'any', label: ll('cheddi.openItemRule.ui.matchAny') },
                ],
                this.draft.match_mode,
                (value) => {
                    this.draft.match_mode = value;
                    this.schedulePreview();
                },
                ll('cheddi.openItemRule.ui.match'),
            );
            match.classList.add('form-select-sm', 'cheddi-rule-editor__match');
            match.dataset.cheddiRuleMatch = '';
            title.appendChild(match);
        }
        conditions.appendChild(title);
        this.draft.conditions.forEach((condition, index) => conditions.appendChild(this.makeConditionRow(condition, index, choices)));
        const add = element('button', 'btn btn-default btn-sm', ll('cheddi.openItemRule.ui.addCondition'));
        add.type = 'button';
        add.dataset.cheddiRuleAddCondition = '';
        add.addEventListener('click', () => {
            this.draft.conditions.push({ field_name: '', comparison: '', compare_value: '', related_table: '', related_field: '' });
            this.render();
        });
        conditions.appendChild(add);

        const preview = element('div', 'alert alert-info mb-0 cheddi-rule-editor__preview');
        preview.dataset.cheddiRulePreview = '';
        preview.setAttribute('aria-live', 'polite');
        form.append(head, conditions, preview, this.makeDetails());
        body.replaceChildren(form);
        this.runPreview();
    }

    renderSourceRule(body) {
        const form = element('div', 'cheddi-rule-editor');
        const source = element('div', 'cheddi-rule-editor__conditions', ll('cheddi.openItemRule.ui.sourceRow', { summary: this.summary }));
        source.dataset.cheddiRuleSource = '';
        const scope = labelled(ll('cheddi.openItemRule.ui.scope'), select(
            this.options.scopes,
            this.draft.root_page,
            (value) => {
                this.draft.root_page = Number(value);
                this.schedulePreview();
            },
            ll('cheddi.openItemRule.ui.scope'),
        ), 'cheddiRuleScope');
        const preview = element('div', 'alert alert-info mb-0 cheddi-rule-editor__preview');
        preview.dataset.cheddiRulePreview = '';
        preview.setAttribute('aria-live', 'polite');
        form.append(source, scope, preview, this.makeDetails());
        body.replaceChildren(form);
        this.runPreview();
    }

    ensureValidCondition(condition, choices) {
        if (condition.comparison === RELATED) {
            return;
        }
        let choice = choices.find((entry) => entry.value === (condition.comparison === AGE ? '' : condition.field_name));
        if (!choice) {
            choice = choices[0];
            condition.field_name = choice?.value ?? '';
        }
        if (condition.comparison === AGE && condition.compare_value === '') {
            condition.compare_value = '180';
        }
        if (!choice?.comparisons.includes(condition.comparison) && condition.comparison !== 'effective_empty') {
            condition.comparison = choice?.comparisons[0] ?? '';
            condition.compare_value = condition.comparison === AGE ? '180' : '';
        }
    }

    makeConditionRow(condition, index, choices) {
        const row = element('div', 'cheddi-rule-editor__row');
        row.dataset.cheddiRuleCondition = String(index);

        if (condition.comparison === RELATED) {
            const related = this.options.tables.find((table) => table.value === condition.related_table)?.label ?? condition.related_table;
            row.appendChild(element('span', 'cheddi-rule-editor__related', ll('cheddi.openItemRule.ui.relatedRow', { table: related })));
        } else {
            const choice = choices.find((entry) => entry.value === (condition.comparison === AGE ? '' : condition.field_name));
            const comparisons = [...(choice?.comparisons ?? [])];
            if (condition.comparison !== '' && !comparisons.includes(condition.comparison)) {
                comparisons.push(condition.comparison);
            }
            const field = select(
                choices.map((entry) => ({ value: entry.value, label: entry.label, title: entry.value })),
                condition.comparison === AGE ? '' : condition.field_name,
                (value) => {
                    condition.field_name = value;
                    condition.comparison = '';
                    condition.compare_value = '';
                    this.render();
                },
                ll('cheddi.openItemRule.ui.field'),
            );
            const comparison = select(
                comparisons.map((entry) => ({ value: entry, label: comparisonLabel(entry) })),
                condition.comparison,
                (value) => {
                    condition.comparison = value;
                    condition.compare_value = WITH_DAYS.includes(value) ? '30' : '';
                    this.render();
                },
                ll('cheddi.openItemRule.ui.condition'),
            );
            row.append(field, comparison);

            if (WITH_VALUE.includes(condition.comparison) || WITH_DAYS.includes(condition.comparison)) {
                const value = element('input', 'form-control');
                const isDays = WITH_DAYS.includes(condition.comparison);
                value.type = isDays ? 'number' : 'text';
                if (isDays) {
                    value.min = '1';
                }
                value.value = condition.compare_value;
                value.setAttribute('aria-label', ll(isDays ? 'cheddi.openItemRule.ui.days' : 'cheddi.openItemRule.ui.value'));
                value.placeholder = ll(isDays ? 'cheddi.openItemRule.ui.days' : 'cheddi.openItemRule.ui.value');
                value.addEventListener('input', () => {
                    condition.compare_value = value.value;
                    this.schedulePreview();
                });
                row.appendChild(value);
            }
        }

        if (this.draft.conditions.length > 1) {
            const remove = element('button', 'btn btn-default btn-sm cheddi-rule-editor__remove');
            remove.type = 'button';
            remove.title = ll('cheddi.openItemRule.ui.removeCondition');
            remove.setAttribute('aria-label', remove.title);
            remove.innerHTML = '<typo3-backend-icon identifier="actions-close" size="small" aria-hidden="true"></typo3-backend-icon>';
            remove.addEventListener('click', () => {
                this.draft.conditions.splice(index, 1);
                this.render();
            });
            row.appendChild(remove);
        }
        return row;
    }

    makeDetails() {
        const details = element('details', 'cheddi-rule-editor__details');
        details.dataset.cheddiRuleDetails = '';
        details.appendChild(element('summary', '', ll('cheddi.openItemRule.ui.adjust')));

        const name = element('input', 'form-control');
        name.type = 'text';
        name.maxLength = 255;
        name.value = this.draft.title;
        name.placeholder = ll('cheddi.openItemRule.ui.autoName');
        name.addEventListener('input', () => {
            this.draft.title = name.value;
        });

        details.append(
            labelled(ll('cheddi.openItemRule.ui.name'), name, 'cheddiRuleName'),
            labelled(ll('cheddi.openItemRule.ui.severity'), select(
                SEVERITIES.map((severity) => ({ value: severity, label: ll(`cheddi.openItemRule.ui.severityOption.${severity}`) })),
                this.draft.severity,
                (value) => {
                    this.draft.severity = value;
                },
                ll('cheddi.openItemRule.ui.severity'),
            ), 'cheddiRuleSeverity'),
        );
        return details;
    }

    schedulePreview() {
        window.clearTimeout(this.previewTimer);
        this.previewRun += 1;
        this.saveButton()?.setAttribute('disabled', 'disabled');
        this.previewTimer = window.setTimeout(() => this.runPreview(), PREVIEW_DELAY_MS);
    }

    async runPreview() {
        this.previewRun += 1;
        const run = this.previewRun;
        this.saveButton()?.setAttribute('disabled', 'disabled');
        let answer = { ok: false, payload: null };
        try {
            answer = await this.api.previewOpenItemRule(this.draft);
        } catch (e) {
            console.error('[ChEddi] could not load the preview of a check.', e);
        }
        const box = this.modal?.querySelector('[data-cheddi-rule-preview]');
        if (run !== this.previewRun || !box) {
            return;
        }

        const payload = answer.payload;
        if (!answer.ok || payload === null) {
            box.textContent = ll('cheddi.openItemRule.ui.previewFailed');
            return;
        }
        if (payload.problem || payload.tooMany) {
            box.textContent = payload.problem ?? ll('cheddi.openItemRule.problem.tooManyMatches');
            return;
        }

        box.replaceChildren(element('div', '', payload.count === 0
            ? ll('cheddi.openItemRule.ui.previewNone')
            : ll('cheddi.openItemRule.ui.previewCount', { count: payload.count })));
        if (payload.examples.length > 0) {
            const list = element('ul', 'mb-0 mt-1');
            for (const example of payload.examples) {
                list.appendChild(element('li', '', example.label === '' ? example.pageTitle : `${example.label} (${example.pageTitle})`));
            }
            box.appendChild(list);
        }
        this.saveButton()?.removeAttribute('disabled');
    }

    async save() {
        this.saveButton()?.setAttribute('disabled', 'disabled');
        let answer = { ok: false, payload: null };
        try {
            answer = await this.api.saveOpenItemRule(this.draft);
        } catch (e) {
            console.error('[ChEddi] could not save a check.', e);
        }

        if (answer.ok) {
            this.modal?.hideModal();
            this.onSaved();
            return;
        }

        const error = answer.payload?.error;
        Notification.error(error?.chatErrorCode === 'openItemRuleInvalid' && error.message
            ? error.message
            : ll('cheddi.openItemRule.ui.saveFailed'), '');
        this.saveButton()?.removeAttribute('disabled');
    }
}
