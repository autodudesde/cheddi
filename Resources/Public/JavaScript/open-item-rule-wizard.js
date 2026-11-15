import Modal from '@typo3/backend/modal.js';
import MultiStepWizard from '@typo3/backend/multi-step-wizard.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import { currentPageId } from '@autodudes/cheddi/backend-context.js';
import { ll } from '@autodudes/cheddi/i18n.js';
import { resetWizard, slideElement } from '@autodudes/cheddi/multi-step-wizard-setup.js';

const LABEL = 'cheddi.openItemRule.goal.';
const GOALS = ['missing', 'stale', 'date', 'word'];
const STALE_DAYS = [90, 180, 365];

function element(tag, className, text = null) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== null) {
        node.textContent = text;
    }
    return node;
}

function radioList(name, entries, current, onChange) {
    const list = element('div', 'cheddi-goal__list');
    list.dataset.cheddiGoalList = name;
    for (const entry of entries) {
        const option = element('label', `cheddi-goal__option${entry.value === current ? ' cheddi-goal__option--active' : ''}`);
        const input = element('input', 'cheddi-goal__radio');
        input.type = 'radio';
        input.name = `cheddi-goal-${name}`;
        input.value = entry.value;
        input.checked = entry.value === current;
        input.addEventListener('change', () => {
            list.querySelectorAll('.cheddi-goal__option--active').forEach((node) => node.classList.remove('cheddi-goal__option--active'));
            option.classList.add('cheddi-goal__option--active');
            onChange(entry.value);
        });
        const text = element('span', 'cheddi-goal__text');
        text.appendChild(element('span', 'cheddi-goal__title', entry.label));
        if (entry.hint) {
            text.appendChild(element('span', 'cheddi-goal__hint', entry.hint));
        }
        option.append(input, text);
        list.appendChild(option);
    }
    return list;
}

export class OpenItemRuleWizard {
    constructor(apiClient, { onCreated, onExpert }) {
        this.api = apiClient;
        this.onCreated = onCreated;
        this.onExpert = onExpert;
        this.draft = null;
        this.options = null;
        this.fields = [];
    }

    async open(isAdmin) {
        let options = null;
        try {
            options = await this.api.fetchOpenItemRuleGoalOptions({ pageId: currentPageId() ?? 0 });
        } catch (e) {
            console.error('[ChEddi] could not load the options of the check wizard.', e);
        }
        if (options === null) {
            Notification.error(ll('cheddi.openItemRule.ui.loadFailed'), '');
            return;
        }
        this.options = options;
        this.isAdmin = isAdmin;
        this.draft = { goal: '', table: '', field: '', value: '', days: 0, dateMode: '', root_page: options.scopes[0]?.value ?? 0, title: '' };

        await resetWizard();
        const title = ll(`${LABEL}title`);
        MultiStepWizard.addSlide('cheddi-goal-what', title, '', Severity.info, ll(`${LABEL}stepWhat`), (slide) => this.renderGoal(slideElement(slide)));
        MultiStepWizard.addSlide('cheddi-goal-table', title, '', Severity.info, ll(`${LABEL}stepTable`), (slide) => this.renderTable(slideElement(slide)));
        MultiStepWizard.addSlide('cheddi-goal-detail', title, '', Severity.info, ll(`${LABEL}stepDetail`), (slide) => this.renderDetail(slideElement(slide)));
        MultiStepWizard.addSlide('cheddi-goal-confirm', title, '', Severity.info, ll(`${LABEL}stepConfirm`), (slide) => this.renderConfirm(slideElement(slide)));
        await MultiStepWizard.addFinalProcessingSlide(() => this.create());
        // Core reads currentModal a frame before it sets it and then skips the first slide.
        Modal.currentModal ??= document.createElement('div');
        MultiStepWizard.show();
    }

    question(container, text) {
        container.replaceChildren(element('h3', 'cheddi-goal__question', text));
    }

    syncNext(ready, label = null) {
        const next = ready ? MultiStepWizard.unlockNextStep() : MultiStepWizard.lockNextStep();
        next.text(label ?? ll(`${LABEL}next`));
    }

    renderGoal(container) {
        this.question(container, ll(`${LABEL}questionWhat`));
        container.appendChild(radioList('what', GOALS.map((goal) => ({
            value: goal,
            label: ll(`${LABEL}${goal}`),
            hint: ll(`${LABEL}${goal}Hint`),
        })), this.draft.goal, (value) => {
            this.draft = { ...this.draft, goal: value, table: '', field: '', value: '', days: 0, dateMode: '', title: '' };
            this.syncNext(true);
        }));
        if (this.isAdmin) {
            const expert = element('button', 'btn btn-link cheddi-goal__expert', ll(`${LABEL}expert`));
            expert.type = 'button';
            expert.dataset.cheddiGoalExpert = '';
            expert.addEventListener('click', () => {
                MultiStepWizard.dismiss();
                this.onExpert();
            });
            container.appendChild(expert);
        }
        this.syncNext(this.draft.goal !== '');
    }

    renderTable(container) {
        this.question(container, ll(`${LABEL}questionTable`));
        container.appendChild(radioList('table', this.options.tables.map((table) => ({
            value: table.value,
            label: table.label,
            hint: ll(`${LABEL}records`, { count: table.records }),
        })), this.draft.table, (value) => {
            this.draft = { ...this.draft, table: value, field: '', value: '', title: '' };
            this.syncNext(true);
        }));
        this.syncNext(this.draft.table !== '');
    }

    detailReady() {
        const { goal, field, value, days, dateMode } = this.draft;
        if (goal === 'stale') {
            return days > 0;
        }
        if (field === '') {
            return false;
        }
        if (goal === 'word') {
            return value.trim() !== '';
        }
        if (goal === 'date') {
            return dateMode === 'passed' || (dateMode === 'within' && days > 0);
        }
        return true;
    }

    async renderDetail(container) {
        const { goal, table } = this.draft;
        this.syncNext(false);
        if (goal === 'stale') {
            this.question(container, ll(`${LABEL}questionStale`));
            container.appendChild(radioList('days', STALE_DAYS.map((days) => ({ value: days, label: ll(`${LABEL}months`, { count: Math.round(days / 30) }) })), this.draft.days, (value) => {
                this.draft.days = value;
                this.draft.title = '';
                this.syncNext(true);
            }));
            this.syncNext(this.detailReady());
            return;
        }

        this.question(container, ll(`${LABEL}question_${goal}`));
        let answer = null;
        try {
            answer = await this.api.fetchOpenItemRuleGoalOptions({ goal, table });
        } catch (e) {
            console.error('[ChEddi] could not load the fields of the check wizard.', e);
        }
        this.fields = answer?.fields ?? [];
        if (this.fields.length === 0) {
            container.appendChild(element('p', 'cheddi-goal__status', ll(`${LABEL}noFields`)));
            return;
        }
        container.appendChild(radioList('field', this.fields, this.draft.field, (value) => {
            this.draft.field = value;
            this.draft.title = '';
            this.syncNext(this.detailReady());
        }));
        if (goal === 'word') {
            container.appendChild(this.wordInput());
        }
        if (goal === 'date') {
            container.appendChild(this.dateChoice());
        }
        this.syncNext(this.detailReady());
    }

    wordInput() {
        const wrapper = element('label', 'cheddi-goal__input');
        wrapper.appendChild(element('span', 'form-label', ll(`${LABEL}wordInput`)));
        const input = element('input', 'form-control');
        input.type = 'text';
        input.maxLength = 100;
        input.value = this.draft.value;
        input.dataset.cheddiGoalWord = '';
        input.addEventListener('input', () => {
            this.draft.value = input.value;
            this.draft.title = '';
            this.syncNext(this.detailReady());
        });
        wrapper.appendChild(input);
        return wrapper;
    }

    dateChoice() {
        const wrapper = element('div', 'cheddi-goal__input');
        wrapper.appendChild(element('span', 'form-label', ll(`${LABEL}dateWhen`)));
        const choose = (mode, days) => {
            this.draft.dateMode = mode;
            this.draft.days = days;
            this.draft.title = '';
            this.syncNext(true);
        };
        wrapper.appendChild(radioList('date', [
            { value: 'passed', label: ll(`${LABEL}datePassed`) },
            { value: 'within', label: ll(`${LABEL}dateWithin`, { count: 30 }) },
        ], this.draft.dateMode, (value) => choose(value, value === 'within' ? 30 : 0)));
        return wrapper;
    }

    payload() {
        return { ...this.draft };
    }

    async renderConfirm(container) {
        this.question(container, ll(`${LABEL}questionConfirm`));
        const result = element('div', 'alert alert-info cheddi-goal__result');
        result.dataset.cheddiGoalResult = '';
        result.setAttribute('aria-live', 'polite');

        const name = element('label', 'cheddi-goal__input');
        name.appendChild(element('span', 'form-label', ll(`${LABEL}name`)));
        const nameInput = element('input', 'form-control');
        nameInput.type = 'text';
        nameInput.maxLength = 255;
        nameInput.dataset.cheddiGoalName = '';
        nameInput.addEventListener('input', () => {
            this.draft.title = nameInput.value;
        });
        name.appendChild(nameInput);

        const where = element('label', 'cheddi-goal__input');
        where.appendChild(element('span', 'form-label', ll(`${LABEL}where`)));
        const scope = element('select', 'form-select');
        scope.dataset.cheddiGoalScope = '';
        for (const entry of this.options.scopes) {
            const option = element('option', '', entry.label);
            option.value = String(entry.value);
            option.selected = entry.value === this.draft.root_page;
            scope.appendChild(option);
        }
        scope.addEventListener('change', () => {
            this.draft.root_page = Number(scope.value);
            this.loadPreview(result, nameInput);
        });
        where.appendChild(scope);

        container.append(result, name, where);
        await this.loadPreview(result, nameInput);
    }

    async loadPreview(result, nameInput) {
        this.syncNext(false, ll(`${LABEL}create`));
        result.textContent = ll(`${LABEL}counting`);
        let answer = { ok: false, payload: null };
        try {
            answer = await this.api.previewOpenItemRuleGoal(this.payload());
        } catch (e) {
            console.error('[ChEddi] could not load the preview of the check wizard.', e);
        }
        const preview = answer.payload;
        if (!answer.ok || !preview) {
            result.className = 'alert alert-danger cheddi-goal__result';
            result.textContent = ll(`${LABEL}previewFailed`);
            return;
        }
        if (this.draft.title === '') {
            this.draft.title = preview.defaultTitle;
        }
        nameInput.value = this.draft.title;
        result.className = `alert ${preview.count > 0 ? 'alert-info' : 'alert-warning'} cheddi-goal__result`;
        result.replaceChildren(element('strong', '', preview.count > 0 ? ll(preview.count === 1 ? `${LABEL}foundOne` : `${LABEL}found`, { count: preview.count }) : ll(`${LABEL}foundNone`)));
        if (preview.examples.length > 0) {
            const examples = element('ul', 'cheddi-goal__examples');
            for (const example of preview.examples) {
                examples.appendChild(element('li', '', example.label || example.pageTitle));
            }
            result.appendChild(examples);
        }
        this.syncNext(true, ll(`${LABEL}create`));
    }

    async create() {
        let result = { ok: false, payload: null };
        try {
            result = await this.api.createOpenItemRuleFromGoal(this.payload());
        } catch (e) {
            console.error('[ChEddi] could not create the check.', e);
        }
        MultiStepWizard.dismiss();
        if (!result.ok) {
            Notification.error(result.payload?.error?.message || ll('cheddi.openItemRule.ui.actionFailed'), '');
            return;
        }
        Notification.success(ll(`${LABEL}created`), '');
        this.onCreated();
    }
}
