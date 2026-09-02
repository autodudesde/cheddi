import { ll } from '@autodudes/cheddi/i18n.js';

/**
 * The conversation list. It owns the panel markup and nothing else: switching to a session and
 * starting a new one are the drawer's business, so they arrive as callbacks. `currentSessionUuid`
 * is read on every render because the drawer replaces it whenever a turn creates a session.
 */
export class SessionsPanel {
    constructor(apiClient, elements, { onSelect, onNew, currentSessionUuid }) {
        this.api = apiClient;
        this.elements = elements;
        this.onSelect = onSelect;
        this.onNew = onNew;
        this.currentSessionUuid = currentSessionUuid;
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
        const button = this.elements.sessionsPrimaryButton;
        if (hasSessions) {
            button.textContent = ll('cheddi.sessions.backToChat');
            button.onclick = () => this.close();
        } else {
            button.textContent = ll('cheddi.sessions.startNew');
            button.onclick = () => {
                this.close();
                this.onNew();
            };
        }
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
        meta.textContent = formatRelativeTime(session.lastActivity);
        text.appendChild(meta);

        openButton.appendChild(text);

        // Signals the row is a switchable conversation.
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

export function formatRelativeTime(unixSeconds) {
    if (typeof unixSeconds !== 'number' || unixSeconds <= 0) {
        return '';
    }
    const diffSeconds = Math.floor(Date.now() / 1000) - unixSeconds;
    if (diffSeconds < 60) {
        return ll('cheddi.time.justNow');
    }
    if (diffSeconds < 3600) {
        return ll('cheddi.time.minutesAgo', { count: Math.floor(diffSeconds / 60) });
    }
    if (diffSeconds < 86400) {
        return ll('cheddi.time.hoursAgo', { count: Math.floor(diffSeconds / 3600) });
    }
    if (diffSeconds < 86400 * 7) {
        return ll('cheddi.time.daysAgo', { count: Math.floor(diffSeconds / 86400) });
    }
    return new Date(unixSeconds * 1000).toLocaleDateString();
}
