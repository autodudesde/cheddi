import { ll } from '@autodudes/cheddi/i18n.js';
import { renderMarkdown } from '@autodudes/cheddi/markdown.js';
import { readBackendContext } from '@autodudes/cheddi/backend-context.js';
import { mapServerError, friendlyToolLabel } from '@autodudes/cheddi/labels.js';
import { defaultThinkingPhases, intentThinkingLabel } from '@autodudes/cheddi/thinking-labels.js';
import { ThinkingIndicator } from '@autodudes/cheddi/thinking-indicator.js';
import { ChatApiClient } from '@autodudes/cheddi/chat-api-client.js';
import { drawerMarkup } from '@autodudes/cheddi/drawer-template.js';
import { WorkspaceReview } from '@autodudes/cheddi/workspace-review.js';
import { HelpModal } from '@autodudes/cheddi/help-modal.js';
import { previewHasInvalidRecords, renderPendingPreview } from '@autodudes/cheddi/confirm-preview.js';
import { AttachmentTray } from '@autodudes/cheddi/attachments.js';
import {
    loadState,
    saveState,
    clampSize,
    clampComposerHeight,
    loadSessionUuid,
    saveSessionUuid,
    loadSessionModel,
    saveSessionModel,
} from '@autodudes/cheddi/state.js';

const CHEDDI_ICON_URL = new URL('../Icons/cheddi.png', import.meta.url).href;
const MAX_AUTO_CONTINUES = 50;
const CREDITS_WARNING_THRESHOLD = 50;

class ChatDrawer {
    constructor(mountPoint) {
        this.mountPoint = mountPoint;
        this.api = new ChatApiClient();
        this.state = loadState();
        this.elements = {};
        this.sessionUuid = loadSessionUuid();
        this.activeAbortController = null;
        this.isSending = false;
        this.creditsAvailable = true;
        this.availableModels = [];
        this.starterTemplates = [];
        this.orientation = null;
        this.workspaceReview = null;
        this.helpModal = null;
        this.attachmentTray = null;
        this.selectedModel = loadSessionModel() ?? '';
        this.modelLocked = false;
        this.creditsWarningShown = false;
        this.modelSwitchNoticeShown = false;
        this.apiKeyNoticeShown = false;
        this.lowBalanceShown = false;
        this.thinking = null;
        this.boundDocumentClick = null;
        this.boundDocumentPointerDown = null;
        this.boundWindowBlur = null;
    }

    init() {
        this.render();
        this.thinking = new ThinkingIndicator(this.elements.messages);
        this.renderQuickActions();
        this.applyState();
        this.bindEvents();
        this.loadAvailableModels();
        this.loadStarterTemplates();
    }

    renderQuickActions() {
        const el = this.elements.quickActions;
        if (!el) {
            return;
        }
        el.innerHTML = '';
        const ctx = readBackendContext();
        this.starterTemplates
            .filter((tpl) => this.templateAppliesToContext(tpl.prompt, ctx))
            .forEach((tpl) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cheddi__quick-action';
                btn.textContent = tpl.name;
                btn.title = tpl.name;
                btn.addEventListener('click', () => this.applyTemplate(tpl.prompt));
                el.appendChild(btn);
            });
    }

    async loadStarterTemplates() {
        try {
            this.starterTemplates = await this.api.fetchTemplates();
        } catch (e) {
            console.warn('[ChEddi] could not load starter templates.', e);
            this.starterTemplates = [];
        }
        this.renderQuickActions();
        this.updateQuickActionsVisibility();
    }

    applyTemplate(prompt) {
        if (this.isSending || !this.creditsAvailable || !this.elements.textarea) {
            return;
        }
        this.elements.textarea.value = this.substituteTemplatePlaceholders(prompt, readBackendContext());
        this.elements.textarea.focus();
        this.elements.textarea.dispatchEvent(new Event('input'));
    }

    substituteTemplatePlaceholders(prompt, ctx) {
        return String(prompt)
            .replace(/\{pageId\}/g, ctx.pageId ? String(ctx.pageId) : '')
            .replace(/\{recordTable\}/g, ctx.recordTable ?? '')
            .replace(/\{recordUid\}/g, ctx.recordUid ? String(ctx.recordUid) : '');
    }

    templateAppliesToContext(prompt, ctx) {
        const needsRecord = /\{record(Table|Uid)\}/.test(String(prompt));
        return needsRecord ? Boolean(ctx.recordUid) : true;
    }

    updateQuickActionsVisibility() {
        const el = this.elements.quickActions;
        if (!el) {
            return;
        }
        const empty = this.elements.messages.querySelectorAll('.cheddi__message').length === 0;
        const usable = this.availableModels.length > 0 && this.creditsAvailable;
        el.hidden = !(empty && usable);
    }

    render() {
        this.mountPoint.hidden = false;
        // The brand icon comes from the TYPO3 icon registry via PHP, so white-label installs
        // get their own mark. Empty string = unresolvable, the template then omits the <img>.
        this.mountPoint.innerHTML = drawerMarkup({
            bubbleIconUrl: CHEDDI_ICON_URL,
            brandIconUrl: this.mountPoint.dataset.cheddiBrandIcon ?? '',
        });

        this.elements = {
            bubble: this.mountPoint.querySelector('[data-cheddi-bubble]'),
            drawer: this.mountPoint.querySelector('[data-cheddi-drawer-shell]'),
            resizeHandle: this.mountPoint.querySelector('[data-cheddi-resize]'),
            composerResizeHandle: this.mountPoint.querySelector('[data-cheddi-composer-resize]'),
            closeButton: this.mountPoint.querySelector('[data-cheddi-close]'),
            minimizeButton: this.mountPoint.querySelector('[data-cheddi-minimize]'),
            messages: this.mountPoint.querySelector('[data-cheddi-messages]'),
            textarea: this.mountPoint.querySelector('[data-cheddi-textarea]'),
            sendButton: this.mountPoint.querySelector('[data-cheddi-send]'),
            credits: this.mountPoint.querySelector('[data-cheddi-credits]'),
            creditsValue: this.mountPoint.querySelector('[data-cheddi-credits-value]'),
            modelSelect: this.mountPoint.querySelector('[data-cheddi-model-select]'),
            orientation: this.mountPoint.querySelector('[data-cheddi-orientation]'),
            orientationBody: this.mountPoint.querySelector('[data-cheddi-orientation-body]'),
            openHelpButton: this.mountPoint.querySelector('[data-cheddi-open-help]'),
            quickActions: this.mountPoint.querySelector('[data-cheddi-quick-actions]'),
            actionsToggle: this.mountPoint.querySelector('[data-cheddi-actions-toggle]'),
            actionsMenu: this.mountPoint.querySelector('[data-cheddi-actions-menu]'),
            newConversationButton: this.mountPoint.querySelector('[data-cheddi-new-conversation]'),
            openSessionsButton: this.mountPoint.querySelector('[data-cheddi-open-sessions]'),
            openReviewButton: this.mountPoint.querySelector('[data-cheddi-open-review]'),
            attachmentButton: this.mountPoint.querySelector('[data-cheddi-attachment]'),
            attachmentChips: this.mountPoint.querySelector('[data-cheddi-attachment-chips]'),
            sessionsPanel: this.mountPoint.querySelector('[data-cheddi-sessions-panel]'),
            sessionsList: this.mountPoint.querySelector('[data-cheddi-sessions-list]'),
            sessionsPrimaryButton: this.mountPoint.querySelector('[data-cheddi-sessions-primary]'),
            closeSessionsButton: this.mountPoint.querySelector('[data-cheddi-close-sessions]'),
        };
    }

    applyState() {
        const { width, height } = clampSize(this.state.width, this.state.height);
        this.state.width = width;
        this.state.height = height;
        this.elements.drawer.style.width = `${width}px`;
        this.elements.drawer.style.height = `${height}px`;
        this.applyComposerHeight();
        this.setOpen(this.state.open, { persist: false });
    }

    applyComposerHeight() {
        const composerHeight = clampComposerHeight(this.state.composerHeight, this.state.height);
        this.state.composerHeight = composerHeight;
        this.elements.textarea.style.height = `${composerHeight}px`;
    }

    bindEvents() {
        this.elements.bubble.addEventListener('click', () => this.setOpen(true));
        this.elements.closeButton.addEventListener('click', () => this.setOpen(false));
        this.elements.minimizeButton.addEventListener('click', () => this.setOpen(false));
        this.elements.sendButton.addEventListener('click', () => this.handlePrimaryAction());
        this.elements.textarea.addEventListener('keydown', (event) => this.onTextareaKeydown(event));
        this.bindResize();
        this.bindComposerResize();

        this.elements.actionsToggle.addEventListener('click', (event) => {
            event.stopPropagation();
            this.toggleActionsMenu();
        });
        this.elements.newConversationButton.addEventListener('click', () => {
            this.closeActionsMenu();
            this.startNewConversation();
        });
        this.elements.openSessionsButton.addEventListener('click', () => {
            this.closeActionsMenu();
            this.openSessionsPanel();
        });
        if (this.elements.openReviewButton) {
            this.elements.openReviewButton.addEventListener('click', () => {
                this.closeActionsMenu();
                this.openWorkspaceReview();
            });
        }
        if (this.elements.openHelpButton) {
            this.elements.openHelpButton.addEventListener('click', () => this.openHelp());
        }
        if (this.elements.attachmentButton && this.elements.attachmentChips) {
            this.attachmentTray = new AttachmentTray(this.api, this.elements.attachmentChips);
            this.elements.attachmentButton.addEventListener('click', () => this.attachmentTray.pick());
            this.elements.attachmentChips.addEventListener('cheddi:attachment-notice', (event) => {
                this.renderMessage({ role: 'system', kind: 'warning', text: event.detail.message });
            });
        }
        this.elements.closeSessionsButton.addEventListener('click', () => this.closeSessionsPanel());
        if (this.elements.modelSelect) {
            this.elements.modelSelect.addEventListener('change', (event) => {
                if (this.modelLocked) {
                    return;
                }
                this.selectedModel = event.target.value;
                saveSessionModel(this.selectedModel);
                if (this.sessionUuid !== null) {
                    this.sessionUuid = null;
                    saveSessionUuid(null);
                }
            });
        }
        this.boundDocumentClick = (event) => {
            if (!this.elements.actionsMenu.contains(event.target)
                && event.target !== this.elements.actionsToggle) {
                this.closeActionsMenu();
            }
        };
        document.addEventListener('click', this.boundDocumentClick);
        this.bindAutoMinimize();
    }

    destroy() {
        if (this.boundDocumentClick) {
            document.removeEventListener('click', this.boundDocumentClick);
        }
        if (this.boundDocumentPointerDown) {
            document.removeEventListener('pointerdown', this.boundDocumentPointerDown);
        }
        if (this.boundWindowBlur) {
            window.removeEventListener('blur', this.boundWindowBlur);
        }
        this.thinking?.hide();
        if (this.activeAbortController) {
            this.activeAbortController.abort();
        }
    }

    bindAutoMinimize() {
        this.boundDocumentPointerDown = (event) => {
            if (!this.state.open) {
                return;
            }
            if (this.elements.drawer.contains(event.target)
                || this.elements.bubble.contains(event.target)) {
                return;
            }
            this.setOpen(false);
        };
        document.addEventListener('pointerdown', this.boundDocumentPointerDown);

        this.boundWindowBlur = () => {
            if (!this.state.open) {
                return;
            }
            window.setTimeout(() => {
                if (this.state.open
                    && document.activeElement
                    && document.activeElement.tagName === 'IFRAME') {
                    this.setOpen(false);
                }
            }, 0);
        };
        window.addEventListener('blur', this.boundWindowBlur);
    }

    toggleActionsMenu() {
        const isOpen = !this.elements.actionsMenu.hidden;
        if (!isOpen) {
            this.updateReviewVisibility();
        }
        this.elements.actionsMenu.hidden = isOpen;
        this.elements.actionsToggle.setAttribute('aria-expanded', String(!isOpen));
    }

    /**
     * Reviewing only makes sense once a conversation exists and its writes land in
     * a draft. In live mode there is nothing to publish or discard.
     */
    updateReviewVisibility() {
        const button = this.elements.openReviewButton;
        if (!button) {
            return;
        }
        const drafts = this.orientation !== null && this.orientation.writeMode !== 'live';
        button.hidden = !drafts || this.sessionUuid === null;
    }

    /**
     * The server appends a marker block for the model; the editor's own bubble should
     * only show the file names, not the instructions meant for the model.
     */
    describeSentMessage(text, attachments) {
        if (attachments.length === 0) {
            return text;
        }
        const names = attachments.map((a) => a.name).join(', ');

        return `${text}\n\n📎 ${names}`;
    }

    openWorkspaceReview() {
        if (this.sessionUuid === null) {
            return;
        }
        if (this.workspaceReview === null) {
            this.workspaceReview = new WorkspaceReview(this.api);
        }
        this.workspaceReview.open(this.sessionUuid);
    }

    openHelp() {
        if (this.helpModal === null) {
            this.helpModal = new HelpModal(this.api);
        }
        this.helpModal.open();
    }

    closeActionsMenu() {
        this.elements.actionsMenu.hidden = true;
        this.elements.actionsToggle.setAttribute('aria-expanded', 'false');
    }

    startNewConversation() {
        this.cancelTurn();
        this.sessionUuid = null;
        saveSessionUuid(null);
        saveSessionModel(null);
        this.modelLocked = false;
        this.applyModelLock();
        this.elements.messages.replaceChildren();
        this.lowBalanceShown = false;
        this.updateQuickActionsVisibility();
        this.creditsAvailable = true;
        this.creditsWarningShown = false;
        this.elements.textarea.disabled = false;
        this.elements.textarea.placeholder = ll('cheddi.ui.textareaPlaceholder', 'Frage oder Anweisung tippen…');
        this.elements.sendButton.disabled = false;
        this.elements.sendButton.textContent = ll('cheddi.ui.send', 'Senden');
        this.updateCredits(null);
        this.elements.textarea.focus();
    }

    onTextareaKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.handlePrimaryAction();
        }
    }

    handlePrimaryAction() {
        if (this.isSending) {
            this.cancelTurn();
            return;
        }
        this.handleSend();
    }

    setOpen(open, { persist = true } = {}) {
        this.state.open = open;
        this.elements.drawer.hidden = !open;
        this.elements.bubble.hidden = open;
        if (persist) {
            saveState(this.state);
        }
    }

    /**
     * Grip sits in the composer's top-left corner, so dragging upwards grows the field,
     * the same direction the drawer's own handle uses.
     */
    bindComposerResize() {
        const handle = this.elements.composerResizeHandle;
        const textarea = this.elements.textarea;
        if (!handle) {
            return;
        }

        const onPointerDown = (event) => {
            if (event.button !== undefined && event.button !== 0) {
                return;
            }
            event.preventDefault();
            const startY = event.clientY;
            const startHeight = textarea.offsetHeight;
            const pointerId = event.pointerId;

            try {
                handle.setPointerCapture(pointerId);
            } catch (err) {
                console.debug('[ChEddi] setPointerCapture failed (pointer gone).', err);
            }
            document.body.classList.add('cheddi-resizing-composer');

            const onPointerMove = (moveEvent) => {
                const proposedHeight = startHeight + (startY - moveEvent.clientY);
                const height = clampComposerHeight(proposedHeight, this.state.height);
                this.state.composerHeight = height;
                textarea.style.height = `${height}px`;
            };

            const onPointerUp = () => {
                handle.removeEventListener('pointermove', onPointerMove);
                handle.removeEventListener('pointerup', onPointerUp);
                handle.removeEventListener('pointercancel', onPointerUp);
                try {
                    handle.releasePointerCapture(pointerId);
                } catch (err) {
                    console.debug('[ChEddi] releasePointerCapture failed (already released).', err);
                }
                document.body.classList.remove('cheddi-resizing-composer');
                saveState(this.state);
            };

            handle.addEventListener('pointermove', onPointerMove);
            handle.addEventListener('pointerup', onPointerUp);
            handle.addEventListener('pointercancel', onPointerUp);
        };

        handle.addEventListener('pointerdown', onPointerDown);
    }

    bindResize() {
        const handle = this.elements.resizeHandle;
        const drawer = this.elements.drawer;

        const onPointerDown = (event) => {
            if (event.button !== undefined && event.button !== 0) {
                return;
            }
            event.preventDefault();
            const startX = event.clientX;
            const startY = event.clientY;
            const startWidth = drawer.offsetWidth;
            const startHeight = drawer.offsetHeight;
            const pointerId = event.pointerId;

            try {
                handle.setPointerCapture(pointerId);
            } catch (err) {
                console.debug('[ChEddi] setPointerCapture failed (pointer gone).', err);
            }
            document.body.classList.add('cheddi-resizing');

            const onPointerMove = (moveEvent) => {
                const proposedWidth = startWidth + (startX - moveEvent.clientX);
                const proposedHeight = startHeight + (startY - moveEvent.clientY);
                const { width, height } = clampSize(proposedWidth, proposedHeight);
                this.state.width = width;
                this.state.height = height;
                drawer.style.width = `${width}px`;
                drawer.style.height = `${height}px`;
            };

            const onPointerUp = () => {
                handle.removeEventListener('pointermove', onPointerMove);
                handle.removeEventListener('pointerup', onPointerUp);
                handle.removeEventListener('pointercancel', onPointerUp);
                try {
                    handle.releasePointerCapture(pointerId);
                } catch (err) {
                    console.debug('[ChEddi] releasePointerCapture failed (already released).', err);
                }
                document.body.classList.remove('cheddi-resizing');
                // A shorter drawer lowers the composer's ceiling, so re-clamp it.
                this.applyComposerHeight();
                saveState(this.state);
            };

            handle.addEventListener('pointermove', onPointerMove);
            handle.addEventListener('pointerup', onPointerUp);
            handle.addEventListener('pointercancel', onPointerUp);
        };

        handle.addEventListener('pointerdown', onPointerDown);
    }

    async openSessionsPanel() {
        this.elements.sessionsPanel.hidden = false;
        await this.loadSessionsList();
    }

    closeSessionsPanel() {
        this.elements.sessionsPanel.hidden = true;
    }

    async loadSessionsList() {
        this.renderSessionsList(await this.api.fetchSessions());
    }

    renderSessionsList(sessions) {
        const list = this.elements.sessionsList;
        list.replaceChildren();

        this.updateSessionsPrimaryButton(sessions.length > 0);

        if (sessions.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'cheddi__sessions-empty';
            empty.textContent = ll('cheddi.sessions.empty', 'Noch keine Konversationen vorhanden.');
            list.appendChild(empty);
            return;
        }

        for (const session of sessions) {
            list.appendChild(this.makeSessionListItem(session));
        }
    }

    updateSessionsPrimaryButton(hasSessions) {
        const button = this.elements.sessionsPrimaryButton;
        if (hasSessions) {
            button.textContent = ll('cheddi.sessions.backToChat', '← Zurück zum aktuellen Chat');
            button.onclick = () => this.closeSessionsPanel();
        } else {
            button.textContent = ll('cheddi.sessions.startNew', '+ Neue Konversation');
            button.onclick = () => {
                this.closeSessionsPanel();
                this.startNewConversation();
            };
        }
    }

    makeSessionListItem(session) {
        const item = document.createElement('li');
        item.className = 'cheddi__sessions-item';
        if (session.uuid === this.sessionUuid) {
            item.classList.add('cheddi__sessions-item--current');
        }

        const openButton = document.createElement('button');
        openButton.type = 'button';
        openButton.className = 'cheddi__sessions-item-open';
        openButton.addEventListener('click', () => this.switchToSession(session.uuid));

        const text = document.createElement('span');
        text.className = 'cheddi__sessions-item-text';

        const title = document.createElement('span');
        title.className = 'cheddi__sessions-item-title';
        title.textContent = session.title !== '' ? session.title : ll('cheddi.sessions.untitled', '(untitled)');
        text.appendChild(title);

        const meta = document.createElement('span');
        meta.className = 'cheddi__sessions-item-meta';
        meta.textContent = this.formatRelativeTime(session.lastActivity);
        text.appendChild(meta);

        openButton.appendChild(text);

        // Signals the row is a switchable conversation.
        const chevron = document.createElement('span');
        chevron.className = 'cheddi__sessions-item-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.textContent = '›';
        openButton.appendChild(chevron);

        item.appendChild(openButton);

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'cheddi__sessions-item-delete';
        deleteButton.setAttribute('aria-label', ll('cheddi.sessions.deleteLabel', 'Konversation löschen'));
        deleteButton.title = ll('cheddi.sessions.delete', 'Löschen');
        deleteButton.textContent = '🗑';
        deleteButton.addEventListener('click', (event) => {
            event.stopPropagation();
            this.deleteSession(session.uuid);
        });
        item.appendChild(deleteButton);

        return item;
    }

    async switchToSession(sessionUuid) {
        try {
            const session = await this.api.loadSession(sessionUuid);
            if (!session) {
                return;
            }

            this.cancelTurn();
            this.sessionUuid = session.uuid;
            saveSessionUuid(session.uuid);
            this.selectedModel = session.model || '';
            saveSessionModel(this.selectedModel);
            this.modelLocked = true;
            this.applyModelLock();
            this.renderModelSelect();

            this.elements.messages.replaceChildren();
            for (const message of session.messages ?? []) {
                this.renderRestoredMessage(message);
            }

            this.closeSessionsPanel();
        } catch (e) {
            console.error('[ChEddi] could not switch to the selected conversation.', e);
        }
    }

    renderRestoredMessage(message) {
        switch (message.role) {
            case 'user':
                this.renderMessage({ role: 'user', text: message.content ?? '' });
                break;
            case 'assistant':
                if (typeof message.content === 'string' && message.content !== '') {
                    this.renderMessage({ role: 'assistant', text: message.content });
                }
                for (const call of message.toolCalls ?? []) {
                    this.renderMessage({ role: 'tool-call', call });
                }
                break;
            case 'tool':
                this.renderMessage({
                    role: 'system',
                    kind: message.toolStatus === 'failed' || message.toolStatus === 'rejected' ? 'warning' : 'info',
                    text: ll('cheddi.history.toolStatus', 'Tool: {status}', { status: message.toolStatus ?? 'done' }),
                });
                break;
            case 'summary':
                this.renderMessage({
                    role: 'summary',
                    text: message.content ?? '',
                });
                break;
        }
    }

    async deleteSession(sessionUuid) {
        try {
            if (!await this.api.deleteSession(sessionUuid)) {
                return;
            }
            if (sessionUuid === this.sessionUuid) {
                this.startNewConversation();
            }
            await this.loadSessionsList();
        } catch (e) {
            console.error('[ChEddi] could not delete the conversation.', e);
        }
    }

    formatRelativeTime(unixSeconds) {
        if (typeof unixSeconds !== 'number' || unixSeconds <= 0) {
            return '';
        }
        const diffSeconds = Math.floor(Date.now() / 1000) - unixSeconds;
        if (diffSeconds < 60) {
            return ll('cheddi.time.justNow', 'just now');
        }
        if (diffSeconds < 3600) {
            return ll('cheddi.time.minutesAgo', '{count} min ago', { count: Math.floor(diffSeconds / 60) });
        }
        if (diffSeconds < 86400) {
            return ll('cheddi.time.hoursAgo', '{count} h ago', { count: Math.floor(diffSeconds / 3600) });
        }
        if (diffSeconds < 86400 * 7) {
            return ll('cheddi.time.daysAgo', '{count} days ago', { count: Math.floor(diffSeconds / 86400) });
        }
        return new Date(unixSeconds * 1000).toLocaleDateString();
    }

    async loadAvailableModels() {
        try {
            const payload = await this.api.fetchModels();
            this.availableModels = Array.isArray(payload?.models) ? payload.models : [];
            this.orientation = payload?.orientation ?? null;
            this.renderOrientation();
        } catch (err) {
            console.warn('[ChEddi] could not load the available chat models.', err);
            this.availableModels = [];
        }

        if (this.selectedModel !== ''
            && this.availableModels.length > 0
            && !this.availableModels.some((m) => m.name === this.selectedModel)) {
            this.renderModelUnavailableNotice();
        }

        this.renderModelSelect();
        this.applyModelLock();
        this.applyNoModelsAvailableState();
        this.applyMissingApiKeyState();
        this.updateQuickActionsVisibility();
    }

    applyMissingApiKeyState() {
        if (this.orientation === null || this.orientation.apiKeyMissing !== true) {
            return;
        }
        this.creditsAvailable = false;
        if (this.elements.textarea) {
            this.elements.textarea.disabled = true;
            this.elements.textarea.placeholder = ll('cheddi.notice.apiKeyMissing.title', 'Bitte hinterlege deinen AI Suite API-Schlüssel in der Erweiterungskonfiguration.');
        }
        if (this.elements.sendButton) {
            this.elements.sendButton.disabled = true;
        }
        if (this.apiKeyNoticeShown) {
            return;
        }
        this.apiKeyNoticeShown = true;
        this.renderMessage({
            role: 'system',
            kind: 'warning',
            text: ll('cheddi.notice.apiKeyMissing.title', 'Bitte hinterlege deinen AI Suite API-Schlüssel in der Erweiterungskonfiguration.')
                + ' '
                + ll('cheddi.notice.apiKeyMissing.message', 'Wenn du noch keinen API-Schlüssel hast, kannst du dir einen unter https://www.autodudes.de anlegen.'),
        });
    }

    renderModelUnavailableNotice() {
        if (this.modelSwitchNoticeShown) {
            return;
        }
        this.modelSwitchNoticeShown = true;
        this.renderMessage({
            role: 'system',
            kind: 'warning',
            text: ll('cheddi.notice.modelUnavailable', 'Note: Your previously selected chat model is no longer available. Please pick a model above. This may be because data-protection (GDPR) mode was activated, which only allows the compliant models.'),
        });
    }

    renderModelSelect() {
        const select = this.elements.modelSelect;
        if (!select) {
            return;
        }
        if (this.availableModels.length === 0) {
            select.hidden = true;
            select.innerHTML = '';
            return;
        }
        const hasValidSelection = this.availableModels.some((m) => m.name === this.selectedModel);
        const options = [];
        if (!hasValidSelection) {
            options.push(`<option value="" disabled selected hidden>${ll('cheddi.notice.selectModelPlaceholder', 'Bitte Modell wählen …')}</option>`);
        }
        for (const m of this.availableModels) {
            const option = document.createElement('option');
            option.value = m.name;
            const rate = Number(m.creditsPerMillion) || 0;
            const meta = [];
            if (rate > 0) {
                meta.push(ll('cheddi.model.rate', '{rate} Credits/Mio. Tokens', { rate }));
            }
            if (m.isGdpr) {
                meta.push(ll('cheddi.model.gdprBadge', 'DSGVO-konform'));
            }
            option.textContent = meta.length > 0
                ? `${m.label || m.name} (${meta.join(' · ')})`
                : (m.label || m.name);
            option.selected = m.name === this.selectedModel;
            options.push(option.outerHTML);
        }
        select.innerHTML = options.join('');
        select.hidden = false;
    }

    renderOrientation() {
        const el = this.elements.orientation;
        const body = this.elements.orientationBody;
        if (!el || !body || !this.orientation) {
            return;
        }
        const o = this.orientation;
        const lines = [
            o.gdprForced
                ? ll('cheddi.orientation.gdprOn', 'Datenschutz: DSGVO-Modus aktiv, es wird nur das datenschutzkonforme Modell angeboten')
                : ll('cheddi.orientation.gdprOff', 'Datenschutz: DSGVO-Modus aus'),
            o.writeMode === 'live'
                ? ll('cheddi.orientation.writeLive', 'Schreibmodus: live, Änderungen wirken direkt auf der Live-Seite')
                : ll('cheddi.orientation.writeWorkspace', 'Schreibmodus: Entwurf, Änderungen landen im Workspace und nicht live'),
            o.webResearch
                ? ll('cheddi.orientation.webResearchOn', 'Web-Recherche: an, Suchanfragen verlassen diese Installation')
                : ll('cheddi.orientation.webResearchOff', 'Web-Recherche: aus, ChEddi arbeitet nur mit Inhalten dieser Installation'),
            ll('cheddi.orientation.confirmation', 'Jede Änderung wird dir vorher als Vorschau gezeigt und erst nach deiner Bestätigung geschrieben.'),
        ];

        const retentionDays = Number(o.sessionLifetimeDays) || 0;
        if (retentionDays > 0) {
            lines.push(ll(
                'cheddi.orientation.retention',
                'Konversationen werden nach {days} Tagen ohne Aktivität gelöscht.',
                { days: retentionDays },
            ));
        }

        body.innerHTML = '';
        for (const text of lines) {
            const li = document.createElement('li');
            li.textContent = text;
            body.appendChild(li);
        }
        el.hidden = false;
    }

    applyModelLock() {
        const select = this.elements.modelSelect;
        if (!select) {
            return;
        }
        select.disabled = this.modelLocked;
        select.title = this.modelLocked
            ? ll('cheddi.notice.modelLocked', 'Das Modell ist pro Konversation festgelegt. Starte eine neue Konversation, um es zu wechseln. Credits fallen pro Antwort an, und ein fortgesetztes Gespräch kostet weniger als ein neues.')
            : '';
    }

    applyNoModelsAvailableState() {
        if (this.availableModels.length > 0) {
            return;
        }
        this.creditsAvailable = false;
        if (this.elements.textarea) {
            this.elements.textarea.disabled = true;
            this.elements.textarea.placeholder = ll('cheddi.notice.noModels', 'Kein Chat-Modell freigeschaltet. Bitte an die Administration wenden.');
        }
        if (this.elements.sendButton) {
            this.elements.sendButton.disabled = true;
        }
    }

    async handleSend() {
        const text = this.elements.textarea.value.trim();
        if (text === '') {
            return;
        }

        if (!this.creditsAvailable) {
            return;
        }
        if (!this.availableModels.some((m) => m.name === this.selectedModel)) {
            this.renderMessage({
                role: 'system',
                kind: 'warning',
                text: ll('cheddi.notice.selectModelFirst', 'Bitte wähle zuerst oben ein Chat-Modell aus.'),
            });
            return;
        }

        // Uploads still in flight are not sent; the tray only hands over stored files.
        const attachments = this.attachmentTray ? this.attachmentTray.pending() : [];

        this.elements.textarea.value = '';
        this.renderMessage({ role: 'user', text: this.describeSentMessage(text, attachments) });
        this.attachmentTray?.clear();
        this.setSending(true);
        this.thinking.showSequence([
            intentThinkingLabel(text),
            ll('cheddi.thinking.moment', 'Einen Moment …'),
            ll('cheddi.thinking.almost', 'Fast fertig …'),
        ]);

        try {
            const result = await this.startTurn(text, attachments);
            await this.processTurnResult(result, 0);
        } catch (error) {
            this.handleTurnError(error);
        } finally {
            this.setSending(false);
        }
    }

    async startTurn(text, attachments = []) {
        if (!this.modelLocked) {
            this.announceActiveModel();
        }

        this.activeAbortController = new AbortController();
        const result = await this.api.startTurn(
            { sessionUuid: this.sessionUuid, text, model: this.selectedModel, attachments },
            this.activeAbortController.signal,
        );
        this.modelLocked = true;
        saveSessionModel(this.selectedModel);
        this.applyModelLock();
        return result;
    }

    announceActiveModel() {
        const model = this.availableModels.find((m) => m.name === this.selectedModel);
        this.renderMessage({
            role: 'system',
            kind: 'info',
            text: ll(
                'cheddi.notice.activeModel',
                'Diese Konversation läuft auf {model}. Starte eine neue Konversation, um zu wechseln.',
                { model: model?.label ?? this.selectedModel },
            ),
        });
    }

    async continueTurn() {
        this.activeAbortController = new AbortController();
        return this.api.continueTurn({ sessionUuid: this.sessionUuid }, this.activeAbortController.signal);
    }

    async processTurnResult(result, autoContinueDepth) {
        if (typeof result?.sessionUuid === 'string' && result.sessionUuid !== '') {
            this.sessionUuid = result.sessionUuid;
            saveSessionUuid(result.sessionUuid);
        }

        for (const notice of result?.notices ?? []) {
            if (typeof notice === 'string' && notice !== '') {
                this.renderMessage({ role: 'system', kind: 'warning', text: notice });
            } else if (notice && typeof notice === 'object' && typeof notice.key === 'string') {
                // Keyed notice: resolve to the editor's language here, with params interpolated.
                this.renderMessage({
                    role: 'system',
                    kind: 'warning',
                    text: ll(notice.key, notice.key, notice.params || {}),
                });
            }
        }

        if (result?.historySummary
            && typeof result.historySummary.summaryContent === 'string'
            && result.historySummary.summaryContent !== '') {
            this.replaceLeadingMessagesWithSummary(
                result.historySummary.summaryContent,
                Array.isArray(result.historySummary.replacedMessageIds)
                    ? result.historySummary.replacedMessageIds.length
                    : 0,
            );
        }

        if (Array.isArray(result?.sources) && result.sources.length > 0) {
            this.renderSources(result.sources);
        }

        switch (result?.status) {
            case 'final':
                if (typeof result.text === 'string' && result.text !== '') {
                    this.renderMessage({ role: 'assistant', text: result.text });
                }
                // After the answer, not before it: the buttons belong to what was just described.
                this.renderNavigationTargets(result.navigationTargets);
                this.updateCredits(result.usage);
                break;

            case 'continuing':
                if (typeof result.text === 'string' && result.text !== '') {
                    this.renderMessage({ role: 'assistant', text: result.text });
                }
                this.renderNavigationTargets(result.navigationTargets);
                for (const call of result.toolCalls ?? []) {
                    this.renderMessage({ role: 'tool-call', call });
                }
                this.updateCredits(result.usage);
                const nextStep = autoContinueDepth + 2;
                this.thinking.updateFromToolCalls(result.toolCalls, nextStep);
                if (autoContinueDepth >= MAX_AUTO_CONTINUES) {
                    this.renderMessage({
                        role: 'system',
                        kind: 'warning',
                        text: ll('cheddi.notice.toolCapReached', 'Maximale Anzahl automatischer Tool-Calls erreicht. Bitte neue Frage stellen.'),
                    });
                    break;
                }
                const next = await this.continueTurn();
                await this.processTurnResult(next, autoContinueDepth + 1);
                break;

            case 'needsConfirm':
                if (typeof result.text === 'string' && result.text !== '') {
                    this.renderMessage({ role: 'assistant', text: result.text });
                }
                for (const call of result.toolCalls ?? []) {
                    this.renderMessage({ role: 'tool-call', call });
                }
                this.renderMessage({ role: 'pending', pending: result.pending ?? [] });
                this.updateCredits(result.usage);
                break;

            case 'creditsExhausted':
                this.creditsAvailable = false;
                this.renderMessage({
                    role: 'system',
                    kind: 'error',
                    text: autoContinueDepth > 0
                        ? ll('cheddi.notice.creditsExhaustedAborted', 'Konversation abgebrochen: Credits aufgebraucht.')
                        : ll('cheddi.notice.creditsExhausted', 'Credits aufgebraucht, bitte aufladen.'),
                });
                this.updateCredits(result.usage);
                this.updateInputDisabledState();
                break;

            case 'aborted':
                if (typeof result.text === 'string' && result.text !== '') {
                    this.renderMessage({ role: 'assistant', text: result.text });
                }
                this.renderMessage({
                    role: 'system',
                    kind: 'warning',
                    text: this.formatAbortReason(result.abortReason),
                });
                this.updateCredits(result.usage);
                break;

            case 'error':
                this.renderMessage({
                    role: 'system',
                    kind: 'error',
                    text: mapServerError(result.error?.chatErrorCode, result.error?.message),
                });
                break;

            default:
                this.renderMessage({
                    role: 'system',
                    kind: 'error',
                    text: ll('cheddi.notice.unexpectedStatus', 'Unexpected status: {status}', { status: String(result?.status ?? 'undefined') }),
                });
        }
    }

    renderNavigationTargets(groups) {
        const usable = (groups ?? [])
            .filter((g) => g && Array.isArray(g.targets))
            .map((g) => ({ ...g, targets: g.targets.filter((t) => t && typeof t.url === 'string' && t.url !== '') }))
            .filter((g) => g.targets.length > 0);
        if (usable.length === 0) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'cheddi__message cheddi__message--system cheddi__nav-targets';
        for (const group of usable) {
            wrapper.appendChild(this.buildNavigationGroup(group));
        }
        this.elements.messages.appendChild(wrapper);
        this.elements.messages.scrollTop = this.elements.messages.scrollHeight;
    }

    buildNavigationGroup(group) {
        const row = document.createElement('div');
        row.className = 'cheddi__nav-group';

        const heading = document.createElement('span');
        heading.className = 'cheddi__nav-heading';
        heading.textContent = group.label || ll('cheddi.notice.navHeading', 'Bearbeiten');
        row.appendChild(heading);

        for (const target of group.targets) {
            const label = target.label || ll('cheddi.notice.navLinkDefault', 'Im Backend öffnen');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'cheddi__nav-link';
            button.title = label;
            const icon = document.createElement('span');
            icon.className = 'cheddi__nav-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '↗';
            const text = document.createElement('span');
            text.className = 'cheddi__nav-label';
            text.textContent = label;
            button.append(icon, text);
            button.addEventListener('click', () => this.navigateBackend(target.url));
            row.appendChild(button);
        }

        if (group.omitted > 0) {
            const more = document.createElement('span');
            more.className = 'cheddi__nav-more';
            more.textContent = ll('cheddi.notice.navMore', '+{count} weitere', { count: String(group.omitted) });
            row.appendChild(more);
        }

        return row;
    }

    navigateBackend(url) {
        try {
            if (top?.TYPO3?.Backend?.ContentContainer?.setUrl) {
                top.TYPO3.Backend.ContentContainer.setUrl(url);
                return true;
            }
        } catch (e) {
            console.debug('[ChEddi] backend ContentContainer navigation failed, trying a new tab.', e);
        }
        try {
            window.open(url, '_blank', 'noopener');
            return true;
        } catch (e) {
            console.error('[ChEddi] could not open the backend location.', e);
            return false;
        }
    }

    renderSources(sources) {
        const usable = sources.filter((s) => s && typeof s.url === 'string' && /^https?:\/\//i.test(s.url));
        if (usable.length === 0) {
            return;
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'cheddi__message cheddi__message--system cheddi__sources';
        const heading = document.createElement('div');
        heading.className = 'cheddi__sources-heading';
        heading.textContent = ll('cheddi.sources.heading', 'Quellen');
        wrapper.appendChild(heading);
        const list = document.createElement('ul');
        list.className = 'cheddi__sources-list';
        for (const source of usable) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.href = source.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = (typeof source.title === 'string' && source.title !== '') ? source.title : source.url;
            item.appendChild(link);
            if (typeof source.text === 'string' && source.text !== '') {
                const details = document.createElement('details');
                details.className = 'cheddi__source-content';
                const summary = document.createElement('summary');
                summary.textContent = ll('cheddi.sources.showContent', 'Gelesenen Inhalt anzeigen');
                const body = document.createElement('pre');
                body.className = 'cheddi__source-content-body';
                body.textContent = source.text;
                details.appendChild(summary);
                details.appendChild(body);
                item.appendChild(details);
            }
            list.appendChild(item);
        }
        wrapper.appendChild(list);
        this.elements.messages.appendChild(wrapper);
        this.elements.messages.scrollTop = this.elements.messages.scrollHeight;
    }

    formatAbortReason(reason) {
        switch (reason) {
            case 'toolCapReached':
                return ll('cheddi.notice.toolCapReachedAbort', 'Tool-Aufruf-Limit erreicht, der Turn wurde abgebrochen. Bitte neue Frage stellen.');
            default:
                return ll('cheddi.notice.turnAborted', 'Turn abgebrochen{reason}.', { reason: reason ? ` (${reason})` : '' });
        }
    }

    handleTurnError(error) {
        if (error?.name === 'AbortError') {
            this.renderMessage({ role: 'system', kind: 'info', text: ll('cheddi.notice.requestAborted', 'Anfrage abgebrochen.') });
            return;
        }
        console.error('[ChEddi] chat turn failed.', error);
        this.renderMessage({
            role: 'system',
            kind: 'error',
            text: mapServerError(error?.chatErrorCode, error?.message),
        });
    }

    cancelTurn() {
        if (this.activeAbortController) {
            this.activeAbortController.abort();
            this.activeAbortController = null;
        }
    }

    setSending(sending) {
        this.isSending = sending;
        this.elements.textarea.disabled = sending;
        this.elements.sendButton.textContent = sending
            ? ll('cheddi.ui.cancel', 'Abbrechen')
            : ll('cheddi.ui.send', 'Senden');
        if (sending) {
            this.thinking.showSequence(defaultThinkingPhases());
        } else {
            this.thinking.hide();
        }
        this.updateInputDisabledState();
    }

    updateInputDisabledState() {
        if (!this.creditsAvailable) {
            this.elements.textarea.disabled = true;
            this.elements.textarea.placeholder = ll('cheddi.credits.exhaustedPlaceholder', 'Credits used up, please top up');
            this.elements.sendButton.disabled = true;
            this.elements.sendButton.textContent = ll('cheddi.ui.locked', 'Locked');
        }
    }

    updateCredits(usage) {
        if (usage && typeof usage.totalCredits === 'number' && usage.totalCredits > 0) {
            top.document.dispatchEvent(new CustomEvent('ai-suite:credits-changed', {
                detail: { persisted: false, source: 'cheddi' },
            }));
        }
        if (usage && usage.lowBalance === true && !this.lowBalanceShown) {
            this.lowBalanceShown = true;
            this.renderMessage({
                role: 'system',
                kind: 'warning',
                text: ll('cheddi.notice.lowBalance', 'Dein Guthaben ist fast aufgebraucht, bitte lade Credits auf, sonst kann ChEddi bald nicht weiterarbeiten.'),
            });
        } else if (usage && usage.lowBalance === false) {
            this.lowBalanceShown = false;
        }
        if (usage && typeof usage.remainingCredits === 'number') {
            const remaining = usage.remainingCredits;
            this.elements.credits.hidden = false;
            this.elements.creditsValue.textContent = String(remaining);
            const belowThreshold = remaining > 0 && remaining < CREDITS_WARNING_THRESHOLD;
            this.elements.credits.classList.toggle('cheddi__header-credits--low', belowThreshold);
            if (belowThreshold && !this.creditsWarningShown) {
                this.creditsWarningShown = true;
                this.renderMessage({
                    role: 'system',
                    kind: 'warning',
                    text: ll('cheddi.notice.lowBalanceRemaining', 'Noch {count} Credits, bitte rechtzeitig aufladen.', { count: remaining }),
                });
            }
            if (remaining >= CREDITS_WARNING_THRESHOLD) {
                this.creditsWarningShown = false;
            }
        } else {
            this.elements.credits.hidden = true;
        }
    }

    renderMessage(message) {
        const el = this.createMessageElement(message);
        this.elements.messages.appendChild(el);
        this.thinking?.keepAtBottom();
        this.updateQuickActionsVisibility();
        this.elements.messages.scrollTop = this.elements.messages.scrollHeight;
    }

    createMessageElement(message) {
        const wrapper = document.createElement('div');
        wrapper.className = `cheddi__message cheddi__message--${message.role}`;

        switch (message.role) {
            case 'user': {
                const bubble = document.createElement('div');
                bubble.className = 'cheddi__bubble-user';
                bubble.textContent = message.text;
                wrapper.appendChild(bubble);
                break;
            }
            case 'assistant': {
                const body = document.createElement('div');
                body.className = 'cheddi__bubble-assistant';
                body.innerHTML = renderMarkdown(message.text);
                wrapper.appendChild(body);
                break;
            }
            case 'tool-call':
                wrapper.appendChild(this.makeToolCallBlock(message.call));
                break;
            case 'system':
                wrapper.classList.add(`cheddi__message--${message.kind ?? 'info'}`);
                wrapper.textContent = message.text;
                break;
            case 'pending':
                wrapper.appendChild(this.makePendingConfirmBlock(message.pending ?? []));
                break;
            case 'summary':
                wrapper.appendChild(this.makeSummaryBlock(message.text, message.replacedCount));
                break;
        }
        return wrapper;
    }

    makeSummaryBlock(summaryText, replacedCount) {
        const details = document.createElement('details');
        details.className = 'cheddi__summary';
        const summary = document.createElement('summary');
        summary.className = 'cheddi__summary-marker';
        const count = typeof replacedCount === 'number' && replacedCount > 0
            ? replacedCount
            : null;
        summary.textContent = count !== null
            ? ll('cheddi.summary.collapsedCount', '── {count} frühere Nachrichten zusammengefasst ──', { count })
            : ll('cheddi.summary.collapsed', '── Frühere Nachrichten zusammengefasst ──');
        details.appendChild(summary);
        const body = document.createElement('div');
        body.className = 'cheddi__summary-body';
        body.textContent = summaryText;
        details.appendChild(body);
        return details;
    }

    replaceLeadingMessagesWithSummary(summaryText, replacedCount) {
        this.elements.messages.replaceChildren();
        this.renderMessage({ role: 'summary', text: summaryText, replacedCount });
    }

    makeToolCallBlock(call) {
        const details = document.createElement('details');
        details.className = 'cheddi__tool-call';

        const summary = document.createElement('summary');
        summary.className = 'cheddi__tool-call-summary';
        summary.textContent = friendlyToolLabel(call?.name);
        if (call?.status) {
            summary.classList.add(`cheddi__tool-call-summary--${call.status}`);
        }
        details.appendChild(summary);

        const args = document.createElement('pre');
        args.className = 'cheddi__tool-call-args';
        args.textContent = JSON.stringify(call?.arguments ?? {}, null, 2);
        details.appendChild(args);

        return details;
    }

    confirmWriteTarget() {
        const mode = this.orientation?.writeMode;
        if (!mode) {
            return '';
        }
        if (mode === 'live') {
            return ll('cheddi.confirm.targetLive', 'Diese Änderungen wirken direkt auf der Live-Seite.');
        }
        if (mode === 'workspace') {
            return ll('cheddi.confirm.targetWorkspace', 'Diese Änderungen landen im Entwurf (Workspace) und nicht live.');
        }
        return ll('cheddi.confirm.targetAuto', 'Diese Änderungen landen im Entwurf (falls vorhanden), sonst live.');
    }

    makePendingConfirmBlock(pending) {
        const container = document.createElement('div');
        container.className = 'cheddi__confirm-block';

        const intro = document.createElement('div');
        intro.className = 'cheddi__confirm-intro';
        intro.textContent = pending.length === 1
            ? ll('cheddi.confirm.introOne', 'Ein Tool-Aufruf braucht Deine Bestätigung:')
            : ll('cheddi.confirm.introMany', '{count} Tool-Aufrufe brauchen Deine Bestätigung:', { count: pending.length });
        container.appendChild(intro);

        const target = this.confirmWriteTarget();
        if (target !== '') {
            const targetEl = document.createElement('div');
            targetEl.className = 'cheddi__confirm-target';
            targetEl.textContent = target;
            container.appendChild(targetEl);
        }

        const decisions = new Map();

        pending.forEach((call) => {
            container.appendChild(this.makeConfirmItem(call, decisions, pending, container));
        });

        if (pending.length > 1) {
            const bulk = document.createElement('div');
            bulk.className = 'cheddi__confirm-bulk';

            const allDecline = document.createElement('button');
            allDecline.type = 'button';
            allDecline.className = 'cheddi__confirm-decline';
            allDecline.textContent = ll('cheddi.confirm.declineAll', 'Alle ablehnen');
            allDecline.addEventListener('click', () => {
                pending.forEach((call) => decisions.set(call.id, false));
                container.querySelectorAll('.cheddi__confirm-item').forEach((item) => {
                    this.markItemDecided(item, 'declined');
                });
                this.maybeSubmitAllDecisions(decisions, pending, container);
            });
            bulk.appendChild(allDecline);
            container.appendChild(bulk);
        }

        return container;
    }

    makeConfirmItem(call, decisions, pending, container) {
        const item = document.createElement('div');
        item.className = 'cheddi__confirm-item';
        item.dataset.cheddiSeverity = call.severity;

        const header = document.createElement('div');
        header.className = 'cheddi__confirm-item-header';
        header.textContent = friendlyToolLabel(call.name);
        if (call.severity === 'destructive') {
            const badge = document.createElement('span');
            badge.className = 'cheddi__confirm-severity-badge';
            badge.textContent = ll('cheddi.confirm.destructiveBadge', '⚠️ schwer umkehrbar');
            header.appendChild(badge);
        }
        item.appendChild(header);

        const preview = renderPendingPreview(call.preview);
        if (preview) {
            item.appendChild(preview);
        }

        const argsDetails = document.createElement('details');
        argsDetails.className = 'cheddi__confirm-item-details';
        if (call.severity === 'destructive') {
            argsDetails.open = true;
        }
        const argsSummary = document.createElement('summary');
        argsSummary.textContent = ll('cheddi.confirm.technicalDetails', 'Technische Details');
        argsDetails.appendChild(argsSummary);
        const args = document.createElement('pre');
        args.className = 'cheddi__confirm-item-args';
        args.textContent = JSON.stringify(call.arguments ?? {}, null, 2);
        argsDetails.appendChild(args);
        item.appendChild(argsDetails);

        const blocked = previewHasInvalidRecords(call.preview);
        if (blocked) {
            item.classList.add('cheddi__confirm-item--blocked');
            const warning = document.createElement('div');
            warning.className = 'cheddi__confirm-blocked';
            warning.textContent = ll(
                'cheddi.confirm.blockedInvalid',
                'Dieser Tool-Aufruf enthält ungültige Datensätze und kann nicht ausgeführt werden. Lehne ihn ab, damit der Assistent ihn korrigiert.',
            );
            item.appendChild(warning);
        }

        const actions = document.createElement('div');
        actions.className = 'cheddi__confirm-item-actions';

        const approveBtn = document.createElement('button');
        approveBtn.type = 'button';
        approveBtn.className = 'cheddi__confirm-approve';
        approveBtn.textContent = call.severity === 'destructive'
            ? ll('cheddi.confirm.executeDestructive', 'Endgültig ausführen')
            : ll('cheddi.confirm.execute', 'Ausführen');
        if (blocked) {
            approveBtn.disabled = true;
            approveBtn.title = ll('cheddi.confirm.blockedInvalidHint', 'Ungültige Datensätze im Payload');
        }
        approveBtn.addEventListener('click', () => {
            if (call.severity === 'destructive' && approveBtn.dataset.armed !== 'true') {
                approveBtn.dataset.armed = 'true';
                approveBtn.textContent = ll('cheddi.confirm.executeDestructiveArmed', 'Wirklich endgültig ausführen?');
                approveBtn.classList.add('cheddi__confirm-approve--armed');
                return;
            }
            decisions.set(call.id, true);
            this.markItemDecided(item, 'approved');
            this.maybeSubmitAllDecisions(decisions, pending, container);
        });

        const declineBtn = document.createElement('button');
        declineBtn.type = 'button';
        declineBtn.className = 'cheddi__confirm-decline';
        declineBtn.textContent = ll('cheddi.confirm.decline', 'Ablehnen');
        declineBtn.addEventListener('click', () => {
            decisions.set(call.id, false);
            this.markItemDecided(item, 'declined');
            this.maybeSubmitAllDecisions(decisions, pending, container);
        });

        actions.appendChild(approveBtn);
        actions.appendChild(declineBtn);
        item.appendChild(actions);

        return item;
    }

    markItemDecided(item, kind) {
        item.classList.add(`cheddi__confirm-item--${kind}`);
        item.querySelectorAll('button').forEach((btn) => {
            btn.disabled = true;
        });
    }

    maybeSubmitAllDecisions(decisions, pending, container) {
        if (decisions.size < pending.length) {
            return;
        }
        const approvals = pending.map((call) => ({
            toolCallId: call.id,
            approved: decisions.get(call.id) === true,
        }));
        this.submitConfirmations(approvals, container);
    }

    async submitConfirmations(approvals, container) {
        container.querySelectorAll('button').forEach((btn) => {
            btn.disabled = true;
        });
        container.classList.add('cheddi__confirm-block--submitted');
        this.setSending(true);

        try {
            this.activeAbortController = new AbortController();
            const result = await this.api.submitConfirmations(
                { sessionUuid: this.sessionUuid, approvals },
                this.activeAbortController.signal,
            );
            await this.processTurnResult(result, 0);
        } catch (error) {
            this.handleTurnError(error);
        } finally {
            this.setSending(false);
        }
    }
}

const mountPoint = document.querySelector('[data-cheddi-drawer]');
if (mountPoint) {
    new ChatDrawer(mountPoint).init();
}

export default ChatDrawer;
