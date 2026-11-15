import Notification from '@typo3/backend/notification.js';
import { ll } from '@autodudes/cheddi/i18n.js';
import { loadingIndicator } from '@autodudes/cheddi/loading-indicator.js';
import { navigateInChatWorkspace } from '@autodudes/cheddi/navigation-targets.js';
import { OpenItemFixer } from '@autodudes/cheddi/open-item-fix.js';

const BATCH_SIZE = 10;
const PAGE_SIZE = 20;
const ACTION_LABELS = {
    workflow: { task: 'cheddi.openItems.action.workflowTask', item: 'cheddi.openItems.action.workflowItem' },
    audit: { task: 'cheddi.openItems.action.auditTask', item: 'cheddi.openItems.action.auditItem' },
};

function element(tag, className, text = null) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== null) {
        node.textContent = text;
    }
    return node;
}

function button(variant, label, dataKey, onClick) {
    const node = element('button', `btn ${variant} btn-sm`, label);
    node.type = 'button';
    node.dataset[dataKey] = '';
    node.addEventListener('click', onClick);
    return node;
}

export class OpenItemsPanel {
    constructor(apiClient, elements, { onNavigate, followWorkspace, onManage, isLive, preferredModel, onVisibility, onPrefill, isInModule }) {
        this.api = apiClient;
        this.elements = elements;
        this.onNavigate = onNavigate;
        this.followWorkspace = followWorkspace;
        this.isLive = isLive;
        this.onVisibility = onVisibility;
        this.onPrefill = onPrefill;
        this.isInModule = isInModule;
        this.payload = null;
        this.loading = false;
        this.taskKey = null;
        this.limit = PAGE_SIZE;
        this.armed = false;
        this.rows = new Map();
        this.currentTask = null;
        this.fixer = new OpenItemFixer(apiClient, { preferredModel, onChange: (uid) => this.repaint(uid) });
        this.elements.closeOpenItemsButton.addEventListener('click', () => this.close());
        this.elements.openItemsBack.addEventListener('click', () => this.showTask(null));
        this.elements.manageOpenItemsButton.addEventListener('click', onManage);
    }

    async open() {
        this.elements.openItemsPanel.hidden = false;
        this.markButton(true);
        this.onVisibility(true);
        this.loading = true;
        this.render();
        await Promise.all([this.refresh(), this.fixer.load()]);
        this.loading = false;
        this.render();
    }

    close() {
        this.elements.openItemsPanel.hidden = true;
        this.markButton(false);
        this.onVisibility(false);
    }

    async modeChanged() {
        if (!this.elements.openItemsPanel.hidden) {
            await this.open();
        }
    }

    markButton(pressed) {
        this.elements.openOpenItemsButton.classList.toggle('active', pressed);
        this.elements.openOpenItemsButton.setAttribute('aria-pressed', String(pressed));
    }

    async refresh() {
        try {
            this.payload = await this.api.fetchOpenItems();
        } catch (e) {
            console.error('[ChEddi] could not load the open items.', e);
            this.payload = null;
        }
        this.fixer.forget((this.payload?.items ?? []).map((item) => item.uid));
        this.renderCount();
        if (!this.elements.openItemsPanel.hidden) {
            this.render();
        }
    }

    renderCount() {
        const tasks = this.payload?.tasks?.length ?? 0;
        const label = tasks === 0 ? ll('cheddi.openItems.title') : ll('cheddi.openItems.taskCount', { count: tasks });

        this.elements.openItemsBadge.hidden = tasks === 0;
        this.elements.openItemsBadge.textContent = String(tasks);
        this.elements.openItemsBadge.title = label;
        this.elements.openItemsCount.hidden = tasks === 0;
        this.elements.openItemsCount.textContent = String(tasks);
        this.elements.openOpenItemsButton.classList.toggle('cheddi__open-items-button--active', tasks > 0);

        const canManage = this.payload?.canManage === true;
        this.elements.openOpenItemsButton.hidden = tasks === 0 && !canManage;
        this.canManage = canManage;
        this.elements.openOpenItemsButton.title = label;
        this.elements.openOpenItemsButton.setAttribute('aria-label', label);
    }

    render() {
        const list = this.elements.openItemsList;
        list.replaceChildren();
        this.rows.clear();
        const task = this.payload === null ? null : (this.payload.tasks ?? []).find((entry) => entry.key === this.taskKey) ?? null;
        this.currentTask = task;
        if (task === null) {
            this.taskKey = null;
        }
        this.elements.openItemsBack.hidden = task === null;
        this.elements.openItemsTitle.textContent = task === null ? ll('cheddi.openItems.title') : `${task.label} (${task.count})`;
        this.elements.manageOpenItemsButton.hidden = task !== null || this.canManage !== true;
        this.paintFooter();

        if (this.loading) {
            this.elements.openItemsFooter.hidden = true;
            list.appendChild(loadingIndicator(ll('cheddi.openItems.loading')));
            return;
        }
        if (this.payload === null) {
            list.appendChild(element('li', 'cheddi__open-items-empty', ll('cheddi.openItems.loadFailed')));
            return;
        }
        if (task !== null) {
            this.renderTask(list, task);
            return;
        }
        this.renderTasks(list);
    }

    renderTasks(list) {
        const tasks = this.payload.tasks ?? [];
        if (tasks.length === 0) {
            list.appendChild(element('li', 'cheddi__open-items-empty', ll('cheddi.openItems.empty')));
            return;
        }
        for (const task of tasks) {
            const row = element('li', `cheddi__open-task cheddi__open-task--${task.severity}`);
            row.dataset.cheddiOpenTask = task.key;
            const count = element('span', 'badge badge-default cheddi__open-task-count', String(task.count));
            const label = element('span', 'cheddi__open-task-label', task.label);
            const actions = element('span', 'cheddi__open-task-actions');
            const actionable = task.fix !== '' && !task.action;
            actions.appendChild(button(
                actionable ? 'btn-primary' : 'btn-default',
                ll(actionable ? 'cheddi.openItems.work' : 'cheddi.openItems.view'),
                'cheddiOpenTaskOpen',
                () => this.showTask(task.key),
            ));
            if (task.action) {
                actions.appendChild(this.actionButton(task.action, 'task', 'btn-primary', 'cheddiOpenTaskAction'));
            }
            row.append(count, label, actions);
            list.appendChild(row);
        }
    }

    showTask(key) {
        this.taskKey = key;
        this.limit = PAGE_SIZE;
        this.armed = false;
        this.render();
        this.elements.openItemsList.scrollTop = 0;
    }

    itemsOf(task) {
        return (this.payload.items ?? []).filter((item) => item.groupKey === task.key && this.fixer.state(item.uid).status !== 'done');
    }

    renderTask(list, task) {
        const items = this.itemsOf(task);
        for (const item of items.slice(0, this.limit)) {
            list.appendChild(this.makeRow(item));
        }
        if (items.length > this.limit) {
            const more = element('li', 'cheddi__open-items-section');
            more.appendChild(button('btn-link', ll('cheddi.openItems.showMore'), 'cheddiOpenTaskMore', () => {
                this.limit += PAGE_SIZE;
                this.render();
            }));
            list.appendChild(more);
        }
    }

    paintFooter() {
        const footer = this.elements.openItemsFooter;
        const task = this.currentTask;
        footer.replaceChildren();
        if (task === null || (task.fix === '' && !task.action)) {
            footer.hidden = true;
            return;
        }
        if (task.fix === '') {
            const actions = element('div', 'cheddi__panel-footer-actions');
            actions.appendChild(this.actionButton(task.action, 'task', 'btn-primary', 'cheddiOpenTaskAction'));
            footer.append(element('div', 'cheddi__panel-footer-info'), actions);
            footer.hidden = false;
            return;
        }

        const info = element('div', 'cheddi__panel-footer-info');
        const actions = element('div', 'cheddi__panel-footer-actions');
        const run = this.fixer.run;
        if (run !== null) {
            info.appendChild(this.progress(ll(run.accepting ? 'cheddi.openItems.fix.progressAccept' : 'cheddi.openItems.fix.progressSuggest', { done: run.done, total: run.total }), run));
            if (!run.accepting) {
                actions.appendChild(button('btn-default', ll('cheddi.openItems.fix.cancel'), 'cheddiOpenTaskCancel', () => this.fixer.cancel()));
            }
        } else {
            const items = this.itemsOf(task);
            const next = this.fixer.pending(items).slice(0, BATCH_SIZE);
            const selected = this.fixer.selected(items);
            const model = this.fixer.modelFor(task.fix);
            if (next.length > 0) {
                info.appendChild(element('span', 'cheddi__open-item-fix-meta', model === null ? ll('cheddi.openItems.fix.noModel') : this.fixer.estimateText(task.fix, next.length)));
                const start = button(selected.length > 0 ? 'btn-default' : 'btn-primary', ll('cheddi.openItems.fix.suggestNext', { count: next.length }), 'cheddiOpenTaskSuggest', () => this.suggest(task));
                start.disabled = model === null;
                actions.appendChild(start);
            }
            if (selected.length > 0) {
                const label = this.armed
                    ? ll('cheddi.openItems.fix.acceptLive', { count: selected.length })
                    : ll('cheddi.openItems.fix.acceptSelected', { count: selected.length });
                actions.appendChild(button(this.armed ? 'btn-danger' : 'btn-primary', label, 'cheddiOpenTaskAccept', () => this.accept(task)));
            } else {
                this.armed = false;
            }
        }
        footer.append(info, actions);
        footer.hidden = actions.childElementCount === 0 && info.childElementCount === 0;
    }

    progress(text, run) {
        const box = element('div', 'cheddi__open-task-progress');
        box.dataset.cheddiOpenTaskProgress = '';
        const bar = element('div', 'cheddi-progress');
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', String(run.total));
        bar.setAttribute('aria-valuenow', String(run.done));
        const fill = element('div', 'cheddi-progress__fill');
        fill.style.width = `${run.total > 0 ? Math.round((run.done / run.total) * 100) : 0}%`;
        bar.appendChild(fill);
        box.append(element('strong', 'cheddi__open-task-progress-text', text), bar);
        return box;
    }

    async suggest(task) {
        const next = this.fixer.pending(this.itemsOf(task)).slice(0, BATCH_SIZE);
        const items = this.itemsOf(task);
        const lastIndex = Math.max(...next.map((item) => items.indexOf(item)));
        if (lastIndex >= this.limit) {
            this.limit = lastIndex + 1;
            this.render();
        }
        await this.fixer.suggestBatch(next);
    }

    async accept(task) {
        if (this.isLive() && !this.armed) {
            this.armed = true;
            this.repaint(null);
            return;
        }
        this.armed = false;
        await this.fixer.acceptBatch(this.fixer.selected(this.itemsOf(task)));
        await this.refresh();
    }

    makeRow(item) {
        const row = element('li', `list-group-item cheddi__open-item cheddi__open-item--${item.severity}`);
        row.dataset.cheddiOpenItem = String(item.uid);
        this.rows.set(item.uid, { row, item });
        this.paintRow(item, row);
        return row;
    }

    paintRow(item, row) {
        const state = this.fixer.state(item.uid);
        row.replaceChildren();

        row.classList.toggle('cheddi__open-item--with-thumbnail', Boolean(item.thumbnail));
        if (item.thumbnail) {
            const thumbnail = element('img', 'cheddi__open-item-thumbnail');
            thumbnail.src = item.thumbnail;
            thumbnail.alt = '';
            thumbnail.loading = 'lazy';
            row.appendChild(thumbnail);
        }
        const head = element('div', 'cheddi__open-item-head');
        if (state.status === 'ready' || state.status === 'saving') {
            const wrapper = element('div', 'form-check cheddi__open-item-check');
            const check = element('input', 'form-check-input');
            check.type = 'checkbox';
            check.dataset.cheddiOpenItemSelect = '';
            check.disabled = state.status === 'saving';
            check.checked = state.selected !== false;
            check.setAttribute('aria-label', ll('cheddi.openItems.fix.select', { title: item.pageTitle }));
            check.addEventListener('change', () => this.fixer.select(item.uid, check.checked));
            wrapper.appendChild(check);
            head.appendChild(wrapper);
        }
        const page = button('btn-link', item.pageTitle, 'cheddiOpenItemView', () => this.openPage(item));
        page.classList.add('cheddi__open-item-page');
        page.title = ll('cheddi.openItems.view');
        head.appendChild(page);
        row.appendChild(head);

        if (item.label !== '') {
            row.appendChild(element('div', 'cheddi__open-item-text', item.label));
        }

        const actions = element('div', 'cheddi__open-item-actions');
        if (item.action) {
            actions.appendChild(this.actionButton(item.action, 'item', 'btn-default', 'cheddiOpenItemAction'));
        } else if (item.chatPrompt) {
            actions.appendChild(button('btn-default', ll('cheddi.openItems.solveWithCheddi'), 'cheddiOpenItemSolve', () => this.solve(item)));
        }
        actions.appendChild(button('btn-default', ll('cheddi.openItems.ignore'), 'cheddiOpenItemResolve', () => this.ignore(item)));
        row.appendChild(actions);
        if (item.fix === '' || state.status === 'idle') {
            return;
        }
        if (state.status === 'loading') {
            row.appendChild(element('div', 'cheddi__open-item-fix-meta', ll('cheddi.openItems.fix.loading')));
            return;
        }
        if (state.status === 'error') {
            row.appendChild(element('div', 'cheddi__open-item-fix-error', state.error));
            return;
        }
        if (state.error) {
            row.appendChild(element('div', 'cheddi__open-item-fix-error', state.error));
        }
        const input = element('textarea', 'form-control form-control-sm cheddi__open-item-fix-input');
        input.dataset.cheddiOpenItemFixInput = '';
        input.rows = 2;
        input.value = state.value;
        input.disabled = state.status === 'saving';
        input.setAttribute('aria-label', ll('cheddi.openItems.fix.by', { model: this.fixer.modelLabel(item.fix, state.model) }));
        input.addEventListener('input', () => this.fixer.edit(item.uid, input.value));
        row.append(input, element('div', 'cheddi__open-item-fix-meta', ll('cheddi.openItems.fix.by', { model: this.fixer.modelLabel(item.fix, state.model) })));
    }

    repaint(uid) {
        if (uid !== null) {
            const entry = this.rows.get(uid);
            if (entry) {
                this.paintRow(entry.item, entry.row);
            }
        }
        this.paintFooter();
    }

    solve(item) {
        if (!this.onPrefill(item.chatPrompt)) {
            Notification.warning(ll('cheddi.openItems.solveUnavailable'), '');
            return;
        }
        if (!this.isInModule()) {
            this.close();
        }
    }

    actionButton(action, scope, variant, dataKey) {
        const labels = ACTION_LABELS[action.kind] ?? ACTION_LABELS.workflow;
        return button(variant, ll(labels[scope]), dataKey, () => this.navigate(action.url));
    }

    openPage(item) {
        this.navigate(item.pageUrl);
    }

    navigate(url) {
        navigateInChatWorkspace(url, this.followWorkspace, () => {
            this.close();
            this.onNavigate();
        });
    }

    async ignore(item) {
        let resolved = false;
        try {
            resolved = await this.api.resolveOpenItem(item.uid);
        } catch (e) {
            console.error('[ChEddi] could not ignore the open item.', e);
        }
        if (!resolved) {
            Notification.error(ll('cheddi.openItems.resolveFailed'), '');
            return;
        }
        await this.refresh();
    }
}
