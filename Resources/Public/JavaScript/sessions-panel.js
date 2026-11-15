import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { ll } from '@autodudes/cheddi/i18n.js';
import { formatFullDateTime, formatLastUsed } from '@autodudes/cheddi/time-format.js';

export class SessionsPanel {
    constructor(apiClient, elements, { onSelect, onNew, currentSessionUuid }) {
        this.api = apiClient;
        this.elements = elements;
        this.onSelect = onSelect;
        this.onNew = onNew;
        this.currentSessionUuid = currentSessionUuid;
        this.permanent = false;
        this.sessions = [];
        this.elements.sessionsNewButton?.addEventListener('click', () => {
            this.close();
            this.onNew();
        });
        this.elements.sessionsSearch?.addEventListener('input', () => this.renderList());
    }

    async open() {
        this.elements.sessionsPanel.hidden = false;
        this.elements.sessionsSearch.value = '';
        await this.reload();
    }

    close() {
        if (this.permanent) {
            return;
        }
        this.elements.sessionsPanel.hidden = true;
    }

    setPermanent(permanent) {
        this.permanent = permanent;
        this.elements.sessionsPanel.hidden = !permanent;
        this.refresh();
    }

    async refresh() {
        if (!this.permanent) {
            return;
        }
        try {
            await this.reload();
        } catch (e) {
            console.error('[ChEddi] could not refresh the conversation list.', e);
        }
    }

    async reload() {
        this.render(await this.api.fetchSessions());
    }

    render(sessions) {
        this.sessions = sessions;
        this.elements.sessionsSearch.hidden = sessions.length === 0;
        this.renderList();
    }

    renderList() {
        const list = this.elements.sessionsList;
        list.replaceChildren();

        const query = this.elements.sessionsSearch.value.trim().toLocaleLowerCase();
        const shown = this.sessions.filter((session) => this.titleOf(session).toLocaleLowerCase().includes(query));
        if (shown.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'cheddi__sessions-empty';
            empty.textContent = this.sessions.length === 0 ? ll('cheddi.sessions.empty') : ll('cheddi.sessions.noMatch');
            list.appendChild(empty);
            return;
        }

        for (const session of shown) {
            list.appendChild(this.makeItem(session));
        }
    }

    titleOf(session) {
        return session.title !== '' ? session.title : ll('cheddi.sessions.untitled');
    }

    makeItem(session) {
        const item = document.createElement('li');
        item.className = 'list-group-item cheddi__sessions-item';
        const isCurrent = session.uuid === this.currentSessionUuid();
        if (isCurrent) {
            item.classList.add('cheddi__sessions-item--current');
        }

        const openButton = document.createElement('button');
        openButton.type = 'button';
        openButton.className = 'cheddi__sessions-item-open';
        openButton.addEventListener('click', () => this.onSelect(session.uuid));
        if (isCurrent) {
            openButton.setAttribute('aria-current', 'true');
        }

        const text = document.createElement('span');
        text.className = 'cheddi__sessions-item-text';

        const title = document.createElement('span');
        title.className = 'cheddi__sessions-item-title';
        title.textContent = this.titleOf(session);
        text.appendChild(title);

        const meta = document.createElement('span');
        meta.className = 'cheddi__sessions-item-meta';
        meta.textContent = formatLastUsed(session.lastActivity);
        meta.title = formatFullDateTime(session.lastActivity);
        text.appendChild(meta);

        if (isCurrent) {
            const badge = document.createElement('span');
            badge.className = 'badge cheddi__sessions-item-badge';
            badge.textContent = ll('cheddi.sessions.current');
            text.appendChild(badge);
        }

        openButton.appendChild(text);

        const chevron = document.createElement('span');
        chevron.className = 'cheddi__sessions-item-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.innerHTML = '<typo3-backend-icon identifier="actions-chevron-right" size="small" aria-hidden="true"></typo3-backend-icon>';
        openButton.appendChild(chevron);

        item.appendChild(openButton);

        const renameButton = document.createElement('button');
        renameButton.type = 'button';
        renameButton.className = 'btn btn-default btn-sm cheddi__header-button cheddi__sessions-item-rename';
        renameButton.setAttribute('aria-label', ll('cheddi.sessions.renameLabel'));
        renameButton.title = ll('cheddi.sessions.rename');
        renameButton.innerHTML = '<typo3-backend-icon identifier="actions-pencil" size="small" aria-hidden="true"></typo3-backend-icon>';
        renameButton.addEventListener('click', (event) => {
            event.stopPropagation();
            this.startRename(item, session);
        });
        const actions = document.createElement('span');
        actions.className = 'cheddi__sessions-item-actions';
        actions.appendChild(renameButton);

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'btn btn-default btn-sm cheddi__header-button cheddi__sessions-item-delete';
        deleteButton.setAttribute('aria-label', ll('cheddi.sessions.deleteLabel'));
        deleteButton.title = ll('cheddi.sessions.delete');
        deleteButton.innerHTML = '<typo3-backend-icon identifier="actions-delete" size="small" aria-hidden="true"></typo3-backend-icon>';
        deleteButton.addEventListener('click', (event) => {
            event.stopPropagation();
            this.confirmDelete(session);
        });
        actions.appendChild(deleteButton);
        item.appendChild(actions);

        return item;
    }

    startRename(item, session) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm cheddi__sessions-item-title-input';
        input.value = session.title;
        input.maxLength = 255;
        input.setAttribute('aria-label', ll('cheddi.sessions.renameLabel'));

        let finished = false;
        const finish = async (save) => {
            if (finished) {
                return;
            }
            finished = true;
            if (save) {
                await this.saveTitle(session, input.value.trim());
            }
            this.renderList();
        };
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                finish(true);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                finish(false);
            }
        });
        input.addEventListener('blur', () => finish(true));

        item.replaceChildren(input);
        input.focus();
        input.select();
    }

    async saveTitle(session, title) {
        if (title === '' || title === session.title) {
            return;
        }
        try {
            if (await this.api.renameSession(session.uuid, title)) {
                session.title = title;
                return;
            }
        } catch (e) {
            console.error('[ChEddi] could not rename the conversation.', e);
        }
        Notification.error(ll('cheddi.sessions.renameFailed'), '');
    }

    confirmDelete(session) {
        const confirmation = Modal.confirm(
            ll('cheddi.sessions.deleteTitle'),
            ll('cheddi.sessions.deleteText', { title: this.titleOf(session) }),
            SeverityEnum.warning,
        );
        confirmation.addEventListener('confirm.button.ok', () => {
            confirmation.hideModal();
            this.delete(session.uuid);
        });
        confirmation.addEventListener('confirm.button.cancel', () => confirmation.hideModal());
    }

    async delete(sessionUuid) {
        try {
            if (!await this.api.deleteSession(sessionUuid)) {
                return;
            }
            if (sessionUuid === this.currentSessionUuid()) {
                this.onNew();
            }
            await this.reload();
        } catch (e) {
            console.error('[ChEddi] could not delete the conversation.', e);
        }
    }
}
