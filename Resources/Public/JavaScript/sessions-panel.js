import { ll } from '@autodudes/cheddi/i18n.js';
import { formatFullDateTime, formatLastUsed } from '@autodudes/cheddi/time-format.js';

export class SessionsPanel {
    constructor(apiClient, elements, { onSelect, onNew, currentSessionUuid }) {
        this.api = apiClient;
        this.elements = elements;
        this.onSelect = onSelect;
        this.onNew = onNew;
        this.currentSessionUuid = currentSessionUuid;
        this.primaryStartsNew = false;
        this.elements.sessionsPrimaryButton?.addEventListener('click', () => this.onPrimary());
    }

    onPrimary() {
        this.close();
        if (this.primaryStartsNew) {
            this.onNew();
        }
    }

    async open() {
        this.elements.sessionsPanel.hidden = false;
        await this.reload();
    }

    close() {
        this.elements.sessionsPanel.hidden = true;
    }

    async reload() {
        this.render(await this.api.fetchSessions());
    }

    render(sessions) {
        const list = this.elements.sessionsList;
        list.replaceChildren();

        this.updatePrimaryButton(sessions.length > 0);

        if (sessions.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'cheddi__sessions-empty';
            empty.textContent = ll('cheddi.sessions.empty');
            list.appendChild(empty);
            return;
        }

        for (const session of sessions) {
            list.appendChild(this.makeItem(session));
        }
    }

    updatePrimaryButton(hasSessions) {
        this.primaryStartsNew = !hasSessions;
        this.elements.sessionsPrimaryButton.textContent = hasSessions
            ? ll('cheddi.sessions.backToChat')
            : ll('cheddi.sessions.startNew');
    }

    makeItem(session) {
        const item = document.createElement('li');
        item.className = 'list-group-item cheddi__sessions-item';
        if (session.uuid === this.currentSessionUuid()) {
            item.classList.add('cheddi__sessions-item--current');
        }

        const openButton = document.createElement('button');
        openButton.type = 'button';
        openButton.className = 'cheddi__sessions-item-open';
        openButton.addEventListener('click', () => this.onSelect(session.uuid));

        const text = document.createElement('span');
        text.className = 'cheddi__sessions-item-text';

        const title = document.createElement('span');
        title.className = 'cheddi__sessions-item-title';
        title.textContent = session.title !== '' ? session.title : ll('cheddi.sessions.untitled');
        text.appendChild(title);

        const meta = document.createElement('span');
        meta.className = 'cheddi__sessions-item-meta';
        meta.textContent = formatLastUsed(session.lastActivity);
        meta.title = formatFullDateTime(session.lastActivity);
        text.appendChild(meta);

        openButton.appendChild(text);

        const chevron = document.createElement('span');
        chevron.className = 'cheddi__sessions-item-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.innerHTML = '<typo3-backend-icon identifier="actions-chevron-right" size="small" aria-hidden="true"></typo3-backend-icon>';
        openButton.appendChild(chevron);

        item.appendChild(openButton);

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'btn btn-default btn-sm cheddi__sessions-item-delete';
        deleteButton.setAttribute('aria-label', ll('cheddi.sessions.deleteLabel'));
        deleteButton.title = ll('cheddi.sessions.delete');
        deleteButton.innerHTML = '<typo3-backend-icon identifier="actions-delete" size="small" aria-hidden="true"></typo3-backend-icon>';
        deleteButton.addEventListener('click', (event) => {
            event.stopPropagation();
            this.delete(session.uuid);
        });
        item.appendChild(deleteButton);

        return item;
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
