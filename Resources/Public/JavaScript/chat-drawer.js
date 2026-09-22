import '@typo3/backend/element/icon-element.js';
import { ll, llOr } from '@autodudes/cheddi/i18n.js';
import { renderMarkdown } from '@autodudes/cheddi/markdown.js';
import { readBackendContext } from '@autodudes/cheddi/backend-context.js';
import { mapServerError, friendlyToolLabel } from '@autodudes/cheddi/labels.js';
import { defaultThinkingPhases } from '@autodudes/cheddi/thinking-labels.js';
import { ThinkingIndicator } from '@autodudes/cheddi/thinking-indicator.js';
import { downloadFile, mergeNavigationGroups, renderNavigationTargets } from '@autodudes/cheddi/navigation-targets.js';
import { formatFullDateTime, formatMessageTime, toIsoString } from '@autodudes/cheddi/time-format.js';
import { SessionsPanel } from '@autodudes/cheddi/sessions-panel.js';
import { renderCreditsBadge, renderModeBadge } from '@autodudes/cheddi/status-bar.js';
import { orientationStatus, renderIntro } from '@autodudes/cheddi/intro-card.js';
import { TemplatesDropdown } from '@autodudes/cheddi/templates-dropdown.js';
import { bindComposerResize, bindResize } from '@autodudes/cheddi/drawer-resize.js';
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
    dockWidth,
    loadSessionUuid,
    saveSessionUuid,
    loadSessionModel,
    saveSessionModel,
} from '@autodudes/cheddi/state.js';

const CHEDDI_ICON_URL = new URL('../Icons/cheddi.png', import.meta.url).href;
const AUTO_CONTINUE_SAFETY_LIMIT = 50;
const CREDITS_REFRESH_DELAY_MS = 1200;
const PROGRESS_POLL_INTERVAL_MS = 900;

const CALLOUT_VARIANTS = {
    info: 'callout-info',
    warning: 'callout-warning',
    error: 'callout-danger',
};

function modalIsOpen() {
    return document.querySelector('dialog[open], .modal.show') !== null;
}

function refreshPageTreeIfPagesChanged(touchedTables) {
    if (!Array.isArray(touchedTables) || !touchedTables.includes('pages')) {
        return;
    }
    top.document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
}

class ChatDrawer {
    constructor(mountPoint) {
        this.mountPoint = mountPoint;
        this.api = new ChatApiClient();
        this.state = loadState();
        this.elements = {};
        this.sessionUuid = loadSessionUuid();
        this.activeAbortController = null;
        this.progressTimer = null;
        this.isSending = false;
        this.creditsAvailable = true;
        this.availableModels = [];
        this.orientation = null;
        this.confirmTarget = null;
        this.credits = null;
        this.starterTemplates = [];
        this.statusAbortController = null;
        this.creditsRefreshTimer = null;
        this.creditsExhausted = false;
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
        this.sessionsPanel = null;
        this.templatesDropdown = null;
        this.contextFillLevel = 'ok';
    }

    init() {
        this.render();
        this.thinking = new ThinkingIndicator(this.elements.messages);
        this.sessionsPanel = new SessionsPanel(this.api, this.elements, {
            onSelect: (uuid) => this.switchToSession(uuid),
            onNew: () => this.startNewConversation(),
            currentSessionUuid: () => this.sessionUuid,
        });
        this.templatesDropdown = new TemplatesDropdown(this.elements, {
            onPick: (prompt) => this.applyTemplate(prompt),
            appliesToContext: (prompt) => this.templateAppliesToContext(prompt, readBackendContext()),
        });
        this.bindEvents();
        this.applyState();
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

    render() {
        this.mountPoint.hidden = false;
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
            dockToggle: this.mountPoint.querySelector('[data-cheddi-dock-toggle]'),
            minimizeButton: this.mountPoint.querySelector('[data-cheddi-minimize]'),
            messages: this.mountPoint.querySelector('[data-cheddi-messages]'),
            textarea: this.mountPoint.querySelector('[data-cheddi-textarea]'),
            sendButton: this.mountPoint.querySelector('[data-cheddi-send]'),
            workspace: this.mountPoint.querySelector('[data-cheddi-workspace]'),
            workspaceLabel: this.mountPoint.querySelector('[data-cheddi-workspace-label]'),
            workspaceTitle: this.mountPoint.querySelector('[data-cheddi-workspace-title]'),
            credits: this.mountPoint.querySelector('[data-cheddi-credits]'),
            creditsValue: this.mountPoint.querySelector('[data-cheddi-credits-value]'),
            modelSelect: this.mountPoint.querySelector('[data-cheddi-model-select]'),
            intro: this.mountPoint.querySelector('[data-cheddi-intro]'),
            introList: this.mountPoint.querySelector('[data-cheddi-intro-list]'),
            introModel: this.mountPoint.querySelector('[data-cheddi-intro-model]'),
            openHelpButton: this.mountPoint.querySelector('[data-cheddi-open-help]'),
            contextNotice: this.mountPoint.querySelector('[data-cheddi-context-notice]'),
            contextNoticeText: this.mountPoint.querySelector('[data-cheddi-context-notice-text]'),
            summarizeButton: this.mountPoint.querySelector('[data-cheddi-summarize]'),
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
            templates: this.mountPoint.querySelector('[data-cheddi-templates]'),
            templatesToggle: this.mountPoint.querySelector('[data-cheddi-templates-toggle]'),
            templatesMenu: this.mountPoint.querySelector('[data-cheddi-templates-menu]'),
            templatesSearch: this.mountPoint.querySelector('[data-cheddi-templates-search]'),
            templatesList: this.mountPoint.querySelector('[data-cheddi-templates-list]'),
        };
    }

    applyState() {
        const { width, height } = clampSize(this.state.width, this.state.height);
        this.state.width = width;
        this.state.height = height;
        this.applyDockMode();
        this.applyComposerHeight();
        this.setOpen(this.state.open, { persist: false });
    }

    setDocked(docked, { persist = true } = {}) {
        this.state.docked = docked;
        this.applyDockMode();
        if (persist) {
            saveState(this.state);
        }
    }

    applyDockMode() {
        const width = this.state.docked && this.state.open
            ? dockWidth(this.state.width, window.innerWidth)
            : null;
        const docked = null !== width;

        this.elements.drawer.classList.toggle('cheddi__drawer--docked', docked);
        this.elements.drawer.style.width = `${docked ? width : this.state.width}px`;
        this.elements.drawer.style.height = docked ? '' : `${this.state.height}px`;

        const shell = document.querySelector('.t3js-scaffold');
        if (shell) {
            shell.style.paddingRight = docked ? `${width}px` : '';
        }

        const label = this.state.docked ? ll('cheddi.ui.undock') : ll('cheddi.ui.dock');
        this.elements.dockToggle.setAttribute('aria-label', label);
        this.elements.dockToggle.title = label;
        const icon = this.state.docked ? 'actions-arrow-left' : 'actions-arrow-right';
        this.elements.dockToggle.innerHTML =
            `<typo3-backend-icon identifier="${icon}" size="small" aria-hidden="true"></typo3-backend-icon>`;
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
        this.elements.dockToggle.addEventListener('click', () => this.setDocked(!this.state.docked));
        this.elements.sendButton.addEventListener('click', () => this.handlePrimaryAction());
        this.elements.summarizeButton.addEventListener('click', () => this.summarizeHistory());
        this.elements.textarea.addEventListener('keydown', (event) => this.onTextareaKeydown(event));
        bindResize(
            this.elements,
            this.state,
            () => this.applyComposerHeight(),
            () => this.applyDockMode(),
        );
        bindComposerResize(this.elements, this.state, () => this.applyComposerHeight());

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
            this.sessionsPanel.open();
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
        this.elements.closeSessionsButton.addEventListener('click', () => this.sessionsPanel.close());
        this.elements.templatesToggle.addEventListener('click', () => {
            this.closeActionsMenu();
            this.templatesDropdown.toggle();
        });
        this.elements.drawer.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.closeOpenPanels();
            }
        });
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
                this.renderIntroCard();
            });
        }
        this.boundDocumentClick = (event) => {
            if (!this.elements.actionsMenu.contains(event.target)
                && event.target !== this.elements.actionsToggle) {
                this.closeActionsMenu();
            }
            if (!this.templatesDropdown.contains(event.target)) {
                this.templatesDropdown.close();
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
            if (!this.state.open || this.state.docked || modalIsOpen()) {
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
                    && !this.state.docked
                    && !modalIsOpen()
                    && document.activeElement
                    && document.activeElement.tagName === 'IFRAME') {
                    this.setOpen(false);
                }
            }, 0);
        };
        window.addEventListener('blur', this.boundWindowBlur);

        this.boundWindowResize = () => this.applyDockMode();
        window.addEventListener('resize', this.boundWindowResize);
    }

    closeOpenPanels() {
        this.templatesDropdown.close();
        if (!this.elements.sessionsPanel.hidden) {
            this.sessionsPanel.close();
        }
    }

    toggleActionsMenu() {
        const isOpen = !this.elements.actionsMenu.hidden;
        if (!isOpen) {
            this.updateReviewVisibility();
        }
        this.elements.actionsMenu.hidden = isOpen;
        this.elements.actionsToggle.setAttribute('aria-expanded', String(!isOpen));
    }

    updateReviewVisibility() {
        const button = this.elements.openReviewButton;
        if (!button) {
            return;
        }
        const drafts = this.orientation !== null && this.orientation.writeMode !== 'live';
        button.hidden = !drafts || this.sessionUuid === null;
    }

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
            this.helpModal = new HelpModal(this.api, () => orientationStatus(this.orientation));
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
        this.selectedModel = '';
        saveSessionModel(null);
        this.ensureModelSelection();
        this.renderModelSelect();
        this.modelLocked = false;
        this.applyModelLock();
        this.elements.messages.replaceChildren();
        this.lowBalanceShown = false;
        this.creditsAvailable = true;
        this.creditsExhausted = false;
        this.creditsWarningShown = false;
        this.elements.textarea.disabled = false;
        this.elements.textarea.placeholder = ll('cheddi.ui.textareaPlaceholder');
        this.elements.sendButton.disabled = false;
        this.elements.sendButton.textContent = ll('cheddi.ui.send');
        this.renderIntroCard();
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
        this.applyDockMode();
        if (persist) {
            saveState(this.state);
        }
        if (open) {
            this.refreshStatus();
        }
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

            this.confirmTarget = session.confirmTarget ?? null;
            if (Array.isArray(session.pending) && session.pending.length > 0) {
                this.renderMessage({ role: 'pending', pending: session.pending });
            }

            this.renderIntroCard();

            this.sessionsPanel.close();
        } catch (e) {
            console.error('[ChEddi] could not switch to the selected conversation.', e);
        }
    }

    renderRestoredSources(message) {
        if (Array.isArray(message.sources) && message.sources.length > 0) {
            this.renderSources(message.sources);
        }
    }

    renderRestoredMessage(message) {
        switch (message.role) {
            case 'user':
                this.renderMessage({ role: 'user', text: message.content ?? '', createdAt: message.createdAt });
                break;
            case 'assistant':
                if (typeof message.content === 'string' && message.content !== '') {
                    this.renderMessage({ role: 'assistant', text: message.content, createdAt: message.createdAt });
                }
                for (const call of message.toolCalls ?? []) {
                    this.renderMessage({ role: 'tool-call', call });
                }
                this.renderDownloads(this.downloadsOfRestoredCalls(message.toolCalls));
                this.renderRestoredSources(message);
                break;
            case 'tool':
                this.renderMessage({
                    role: 'system',
                    kind: message.toolStatus === 'failed' || message.toolStatus === 'rejected' ? 'warning' : 'info',
                    text: ll('cheddi.history.toolStatus', { status: message.toolStatus ?? 'done' }),
                });
                this.renderRestoredSources(message);
                break;
            case 'summary':
                this.renderMessage({
                    role: 'summary',
                    text: message.content ?? '',
                });
                break;
        }
    }

    async refreshStatus({ refreshCredits = false } = {}) {
        if (this.isSending && !refreshCredits) {
            return;
        }
        if (this.statusAbortController) {
            this.statusAbortController.abort();
        }
        const controller = new AbortController();
        this.statusAbortController = controller;

        let payload;
        try {
            payload = await this.api.fetchStatus({ refreshCredits }, controller.signal);
        } catch (err) {
            if (err?.name !== 'AbortError') {
                console.warn('[ChEddi] could not load the chat status.', err);
            }
            return;
        } finally {
            if (this.statusAbortController === controller) {
                this.statusAbortController = null;
            }
        }

        this.availableModels = Array.isArray(payload?.models) ? payload.models : [];
        this.orientation = payload?.orientation ?? null;
        this.credits = payload?.credits ?? null;
        this.starterTemplates = Array.isArray(payload?.templates) ? payload.templates : [];

        this.templatesDropdown.setTemplates(this.starterTemplates);
        this.attachmentTray?.applyLimits(payload?.attachments ?? null);
        this.renderStatusBadges();
        this.resetAvailabilityState();

        if (this.selectedModel !== ''
            && this.availableModels.length > 0
            && !this.availableModels.some((m) => m.name === this.selectedModel)) {
            this.renderModelUnavailableNotice();
        }

        this.ensureModelSelection();
        this.renderModelSelect();
        this.applyModelLock();
        this.applyNoModelsAvailableState();
        this.applyMissingApiKeyState();
        this.renderIntroCard();
    }

    resetAvailabilityState() {
        if (this.creditsExhausted) {
            return;
        }
        this.creditsAvailable = true;
        this.elements.textarea.disabled = this.isSending;
        this.elements.textarea.placeholder = ll('cheddi.ui.textareaPlaceholder');
        this.elements.sendButton.disabled = false;
        this.elements.sendButton.textContent = this.isSending
            ? ll('cheddi.ui.cancel')
            : ll('cheddi.ui.send');
    }

    renderStatusBadges() {
        renderModeBadge(this.elements, this.orientation);
        renderCreditsBadge(this.elements, this.credits);
    }

    renderIntroCard() {
        renderIntro(this.elements, {
            orientation: this.orientation,
            visible: this.elements.messages.childElementCount === 0,
            activeModelLabel: this.activeModelLabel(),
        });
    }

    activeModelLabel() {
        const model = this.availableModels.find((m) => m.name === this.selectedModel);

        return model ? (model.label || model.name) : '';
    }

    scheduleCreditsRefresh() {
        if (this.creditsRefreshTimer !== null) {
            window.clearTimeout(this.creditsRefreshTimer);
        }
        this.creditsRefreshTimer = window.setTimeout(() => {
            this.creditsRefreshTimer = null;
            this.refreshStatus({ refreshCredits: true });
        }, CREDITS_REFRESH_DELAY_MS);
    }

    applyMissingApiKeyState() {
        if (this.orientation === null || this.orientation.apiKeyMissing !== true) {
            return;
        }
        this.creditsAvailable = false;
        if (this.elements.textarea) {
            this.elements.textarea.disabled = true;
            this.elements.textarea.placeholder = ll('cheddi.notice.apiKeyMissing.title');
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
            text: ll('cheddi.notice.apiKeyMissing.title')
                + ' '
                + ll('cheddi.notice.apiKeyMissing.message'),
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
            text: ll('cheddi.notice.modelUnavailable'),
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
        const options = [];
        for (const m of this.availableModels) {
            const option = document.createElement('option');
            option.value = m.name;
            const rate = Number(m.creditsPerMillion) || 0;
            const meta = [];
            if (rate > 0) {
                meta.push(ll('cheddi.model.rate', { rate }));
            }
            if (m.isGdpr) {
                meta.push(ll('cheddi.model.gdprBadge'));
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

    ensureModelSelection() {
        if (this.availableModels.length === 0) {
            return;
        }
        if (this.availableModels.some((m) => m.name === this.selectedModel)) {
            return;
        }

        this.selectedModel = this.availableModels[0].name;
        saveSessionModel(this.selectedModel);
    }

    applyModelLock() {
        const select = this.elements.modelSelect;
        if (!select) {
            return;
        }
        select.disabled = this.modelLocked;
        select.title = this.modelLocked
            ? ll('cheddi.notice.modelLocked')
            : '';
    }

    applyNoModelsAvailableState() {
        if (this.availableModels.length > 0) {
            return;
        }
        this.creditsAvailable = false;
        if (this.elements.textarea) {
            this.elements.textarea.disabled = true;
            this.elements.textarea.placeholder = ll('cheddi.notice.noModels');
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
                text: ll('cheddi.notice.selectModelFirst'),
            });
            return;
        }

        const attachments = this.attachmentTray ? this.attachmentTray.pending() : [];

        this.elements.textarea.value = '';
        this.renderMessage({ role: 'user', text: this.describeSentMessage(text, attachments) });
        this.attachmentTray?.clear();
        this.setSending(true);
        this.thinking.showSequence(defaultThinkingPhases());

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
        this.renderMessage({
            role: 'system',
            kind: 'info',
            text: ll('cheddi.notice.activeModel', { model: this.activeModelLabel() || this.selectedModel }),
        });
    }

    startProgressPolling() {
        this.stopProgressPolling();
        this.progressTimer = window.setInterval(async () => {
            const progress = await this.api.fetchTurnProgress(this.sessionUuid);
            if (!progress || progress.phase !== 'tool' || !this.isSending) {
                return;
            }
            this.thinking.show(
                friendlyToolLabel(progress.tool) || ll('cheddi.thinking.default'),
                Number(progress.step) || 0,
                Number(progress.total) || 0,
            );
        }, PROGRESS_POLL_INTERVAL_MS);
    }

    stopProgressPolling() {
        if (this.progressTimer) {
            window.clearInterval(this.progressTimer);
            this.progressTimer = null;
        }
    }

    async continueTurn() {
        this.activeAbortController = new AbortController();
        return this.api.continueTurn({ sessionUuid: this.sessionUuid }, this.activeAbortController.signal);
    }

    collectFoundTargets(result) {
        if (Array.isArray(result?.navigationTargets) && result.navigationTargets.length > 0) {
            this.chainTouchedRecords = true;
        }
        this.foundTargets = mergeNavigationGroups(this.foundTargets, result?.foundTargets);
    }

    async processTurnResult(result, autoContinueDepth) {
        if (autoContinueDepth === 0) {
            this.foundTargets = [];
            this.chainTouchedRecords = false;
        }
        if (typeof result?.sessionUuid === 'string' && result.sessionUuid !== '') {
            this.sessionUuid = result.sessionUuid;
            saveSessionUuid(result.sessionUuid);
        }

        for (const notice of result?.notices ?? []) {
            if (typeof notice === 'string' && notice !== '') {
                this.renderMessage({ role: 'system', kind: 'warning', text: notice });
            } else if (notice && typeof notice === 'object' && typeof notice.key === 'string') {
                this.renderMessage({
                    role: 'system',
                    kind: 'warning',
                    text: llOr(notice.key, notice.key, notice.params || {}),
                });
            }
        }

        if (result?.historySummary
            && typeof result.historySummary.summaryContent === 'string'
            && result.historySummary.summaryContent !== '') {
            this.replaceLeadingMessagesWithSummary(result.historySummary.summaryContent);
        }

        if (Array.isArray(result?.sources) && result.sources.length > 0) {
            this.renderSources(result.sources);
        }

        refreshPageTreeIfPagesChanged(result?.touchedTables);

        let continueAfterRender = false;

        switch (result?.status) {
            case 'final':
                if (typeof result.text === 'string' && result.text !== '') {
                    this.renderMessage({ role: 'assistant', text: result.text });
                }
                renderNavigationTargets(this.elements.messages, result.navigationTargets, this.navigationOptions());
                this.collectFoundTargets(result);
                if (!this.chainTouchedRecords) {
                    renderNavigationTargets(this.elements.messages, this.foundTargets, this.navigationOptions({ found: true }));
                }
                this.foundTargets = [];
                this.updateCredits(result.usage);
                this.updateContextFill(result);
                break;

            case 'continuing':
                if (typeof result.text === 'string' && result.text !== '') {
                    this.renderMessage({ role: 'assistant', text: result.text });
                }
                renderNavigationTargets(this.elements.messages, result.navigationTargets, this.navigationOptions());
                this.collectFoundTargets(result);
                for (const call of result.toolCalls ?? []) {
                    this.renderMessage({ role: 'tool-call', call });
                }
                this.updateCredits(result.usage);
                this.updateContextFill(result);
                const nextStep = autoContinueDepth + 2;
                this.thinking.updateFromToolCalls(result.toolCalls, nextStep);
                if (autoContinueDepth >= AUTO_CONTINUE_SAFETY_LIMIT) {
                    this.renderMessage({
                        role: 'system',
                        kind: 'warning',
                        text: ll('cheddi.notice.toolCapReached'),
                    });
                    break;
                }
                continueAfterRender = true;
                break;

            case 'needsConfirm':
                this.confirmTarget = result.confirmTarget ?? null;
                if (typeof result.text === 'string' && result.text !== '') {
                    this.renderMessage({ role: 'assistant', text: result.text });
                }
                for (const call of result.toolCalls ?? []) {
                    this.renderMessage({ role: 'tool-call', call });
                }
                this.renderMessage({ role: 'pending', pending: result.pending ?? [] });
                this.updateCredits(result.usage);
                this.updateContextFill(result);
                break;

            case 'creditsExhausted':
                this.creditsAvailable = false;
                this.creditsExhausted = true;
                this.renderMessage({
                    role: 'system',
                    kind: 'error',
                    text: autoContinueDepth > 0
                        ? ll('cheddi.notice.creditsExhaustedAborted')
                        : ll('cheddi.notice.creditsExhausted'),
                });
                this.updateCredits(result.usage);
                this.updateContextFill(result);
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
                this.updateContextFill(result);
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
                    text: ll('cheddi.notice.unexpectedStatus', { status: String(result?.status ?? 'undefined') }),
                });
        }

        this.renderDownloads(result?.downloads);

        if (continueAfterRender) {
            const next = await this.continueTurn();
            await this.processTurnResult(next, autoContinueDepth + 1);
        }
    }

    navigationOptions(options = {}) {
        return {
            ...options,
            onNavigate: () => {
                if (!this.state.docked) {
                    this.setOpen(false);
                }
            },
        };
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
        heading.textContent = ll('cheddi.sources.heading');
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
                summary.textContent = ll('cheddi.sources.showContent');
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
                return ll('cheddi.notice.toolCapReachedAbort');
            default:
                return ll('cheddi.notice.turnAborted', { reason: reason ? ` (${reason})` : '' });
        }
    }

    handleTurnError(error) {
        if (error?.name === 'AbortError') {
            this.renderMessage({ role: 'system', kind: 'info', text: ll('cheddi.notice.requestAborted') });
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
            ? ll('cheddi.ui.cancel')
            : ll('cheddi.ui.send');
        if (sending) {
            this.thinking.showSequence(defaultThinkingPhases());
            this.startProgressPolling();
        } else {
            this.stopProgressPolling();
            this.thinking.hide();
        }
        this.updateInputDisabledState();
    }

    updateInputDisabledState() {
        if (!this.creditsAvailable) {
            this.elements.textarea.disabled = true;
            this.elements.textarea.placeholder = ll('cheddi.credits.exhaustedPlaceholder');
            this.elements.sendButton.disabled = true;
            this.elements.sendButton.textContent = ll('cheddi.ui.locked');
        }
    }

    updateCredits(usage) {
        if (!usage) {
            return;
        }
        if (typeof usage.totalCredits === 'number' && usage.totalCredits > 0) {
            top.document.dispatchEvent(new CustomEvent('ai-suite:credits-changed', {
                detail: { persisted: false, source: 'cheddi' },
            }));
        }
        if (usage.lowBalance === true && !this.lowBalanceShown) {
            this.lowBalanceShown = true;
            this.renderMessage({
                role: 'system',
                kind: 'warning',
                text: ll('cheddi.notice.lowBalance'),
            });
        } else if (usage.lowBalance === false) {
            this.lowBalanceShown = false;
        }
        if (usage.lowRemaining === true && !this.creditsWarningShown) {
            this.creditsWarningShown = true;
            this.renderMessage({
                role: 'system',
                kind: 'warning',
                text: ll('cheddi.notice.lowBalanceRemaining', { count: usage.remainingCredits }),
            });
        } else if (usage.lowRemaining === false) {
            this.creditsWarningShown = false;
        }

        this.scheduleCreditsRefresh();
    }

    updateContextFill(result) {
        const level = result?.contextFill?.level;
        if (typeof level === 'string' && level !== '') {
            this.contextFillLevel = level;
        }
        this.renderContextNotice();
    }

    renderContextNotice() {
        const notice = this.elements.contextNotice;
        if (!notice) {
            return;
        }
        if (this.contextFillLevel !== 'notice' && this.contextFillLevel !== 'warning') {
            notice.hidden = true;
            notice.classList.remove('cheddi__context-notice--critical');
            return;
        }
        const critical = this.contextFillLevel === 'warning';
        notice.classList.toggle('cheddi__context-notice--critical', critical);
        this.elements.contextNoticeText.textContent = critical
            ? ll('cheddi.context.warning')
            : ll('cheddi.context.notice');
        notice.hidden = false;
    }

    async summarizeHistory() {
        if (this.isSending || this.sessionUuid === '') {
            return;
        }
        this.isSending = true;
        this.elements.summarizeButton.disabled = true;
        this.thinking?.show(ll('cheddi.context.summarizing'));
        try {
            const result = await this.api.summarizeHistory({ sessionUuid: this.sessionUuid });
            if (result?.status === 'error') {
                this.renderMessage({
                    role: 'system',
                    kind: 'error',
                    text: mapServerError(result?.error?.chatErrorCode, result?.error?.message),
                });
                return;
            }
            if (typeof result?.historySummary?.summaryContent === 'string'
                && result.historySummary.summaryContent !== '') {
                this.replaceLeadingMessagesWithSummary(
                    result.historySummary.summaryContent,
                    result.historySummary.replacedCount,
                );
            }
            this.updateCredits(result?.usage);
            this.updateContextFill(result);
        } catch (err) {
            this.renderMessage({
                role: 'system',
                kind: 'error',
                text: mapServerError(err?.chatErrorCode, err?.message),
            });
        } finally {
            this.thinking?.hide();
            this.elements.summarizeButton.disabled = false;
            this.isSending = false;
        }
    }

    renderMessage(message) {
        const el = this.createMessageElement(message);
        if (this.elements.intro) {
            this.elements.intro.hidden = true;
        }
        this.elements.messages.appendChild(el);
        this.thinking?.keepAtBottom();
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
                wrapper.appendChild(this.makeTimeElement(message.createdAt));
                break;
            }
            case 'assistant': {
                const body = document.createElement('div');
                body.className = 'cheddi__bubble-assistant';
                body.innerHTML = renderMarkdown(message.text);
                wrapper.appendChild(body);
                wrapper.appendChild(this.makeTimeElement(message.createdAt));
                break;
            }
            case 'tool-call':
                wrapper.appendChild(this.makeToolCallBlock(message.call));
                break;
            case 'system': {
                const kind = message.kind ?? 'info';
                wrapper.classList.add('callout', CALLOUT_VARIANTS[kind] ?? CALLOUT_VARIANTS.info);
                wrapper.classList.add(`cheddi__message--${kind}`);
                wrapper.textContent = message.text;
                break;
            }
            case 'pending':
                wrapper.appendChild(this.makePendingConfirmBlock(message.pending ?? []));
                break;
            case 'summary':
                wrapper.appendChild(this.makeSummaryBlock(message.text, message.replacedCount));
                break;
        }
        return wrapper;
    }

    downloadsOfRestoredCalls(calls) {
        return (calls ?? [])
            .filter((call) => call?.name === 'createCsvDownload' && Array.isArray(call?.arguments?.rows))
            .map((call) => ({
                callId: call.id,
                filename: typeof call.arguments.filename === 'string' ? call.arguments.filename : '',
                rowCount: call.arguments.rows.length,
            }));
    }

    renderDownloads(downloads) {
        const wrapper = document.createElement('div');
        wrapper.className = 'cheddi__message cheddi__message--system cheddi__nav-targets cheddi__downloads';

        for (const download of downloads ?? []) {
            const url = this.api.csvDownloadUrl(this.sessionUuid, download?.callId);
            if (!url) {
                continue;
            }
            const row = document.createElement('div');
            row.className = 'cheddi__nav-group';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-default btn-sm cheddi__nav-link cheddi__download-link';
            const icon = document.createElement('span');
            icon.className = 'cheddi__nav-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.innerHTML = '<typo3-backend-icon identifier="actions-download" size="small" aria-hidden="true"></typo3-backend-icon>';
            const label = document.createElement('span');
            label.className = 'cheddi__nav-label';
            label.textContent = ll('cheddi.download.button', { filename: String(download.filename || 'export.csv') });
            button.append(icon, label);
            button.addEventListener('click', () => downloadFile(url));

            const meta = document.createElement('span');
            meta.className = 'cheddi__nav-more';
            meta.textContent = ll('cheddi.download.rows', { count: String(Number(download.rowCount) || 0) });

            row.append(button, meta);
            wrapper.appendChild(row);
        }

        if (wrapper.childElementCount === 0) {
            return;
        }
        this.elements.messages.appendChild(wrapper);
        this.elements.messages.scrollTop = this.elements.messages.scrollHeight;
    }

    makeTimeElement(createdAt) {
        const unixSeconds = typeof createdAt === 'number' && createdAt > 0 ? createdAt : Math.floor(Date.now() / 1000);
        const time = document.createElement('time');
        time.className = 'cheddi__message-time';
        time.dateTime = toIsoString(unixSeconds);
        time.title = formatFullDateTime(unixSeconds);
        time.textContent = formatMessageTime(unixSeconds);
        return time;
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
            ? ll('cheddi.summary.collapsedCount', { count })
            : ll('cheddi.summary.collapsed');
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

    makePendingConfirmBlock(pending) {
        const container = document.createElement('div');
        container.className = 'cheddi__confirm-block';

        const intro = document.createElement('div');
        intro.className = 'cheddi__confirm-intro';
        intro.textContent = pending.length === 1
            ? ll('cheddi.confirm.introOne')
            : ll('cheddi.confirm.introMany', { count: pending.length });
        container.appendChild(intro);

        if (this.confirmTarget) {
            const targetEl = document.createElement('div');
            targetEl.className = 'cheddi__confirm-target';
            targetEl.textContent = ll(this.confirmTarget.key, this.confirmTarget.params ?? {});
            container.appendChild(targetEl);
        }

        const decisions = new Map();
        const items = new Map();

        pending.forEach((call) => {
            const item = this.makeConfirmItem(call, decisions, pending, container, previewHasInvalidRecords(call.preview));
            items.set(call.id, item);
            container.appendChild(item);
        });

        if (pending.length > 1) {
            const bulk = document.createElement('div');
            bulk.className = 'cheddi__confirm-bulk';

            const bulkApprovable = pending.filter(
                (call) => 'destructive' !== call.severity && !previewHasInvalidRecords(call.preview),
            );

            if (bulkApprovable.length > 0) {
                const allApprove = document.createElement('button');
                allApprove.type = 'button';
                allApprove.className = 'btn btn-primary btn-sm cheddi__confirm-approve';
                allApprove.textContent = ll('cheddi.confirm.executeAll');
                allApprove.addEventListener('click', () => {
                    allApprove.disabled = true;
                    bulkApprovable.forEach((call) => {
                        if (decisions.has(call.id)) {
                            return;
                        }
                        decisions.set(call.id, true);
                        const item = items.get(call.id);
                        if (item) {
                            this.markItemDecided(item, 'approved');
                        }
                    });
                    this.maybeSubmitAllDecisions(decisions, pending, container);
                });
                bulk.appendChild(allApprove);
            }

            const allDecline = document.createElement('button');
            allDecline.type = 'button';
            allDecline.className = 'btn btn-default btn-sm cheddi__confirm-decline';
            allDecline.textContent = ll('cheddi.confirm.declineAll');
            allDecline.addEventListener('click', () => {
                pending.forEach((call) => {
                    if (decisions.has(call.id)) {
                        return;
                    }
                    decisions.set(call.id, false);
                    const item = items.get(call.id);
                    if (item) {
                        this.markItemDecided(item, 'declined');
                    }
                });
                this.maybeSubmitAllDecisions(decisions, pending, container);
            });
            bulk.appendChild(allDecline);
            container.appendChild(bulk);
        }

        return container;
    }

    makeConfirmItem(call, decisions, pending, container, blocked) {
        const item = document.createElement('div');
        item.className = 'cheddi__confirm-item';
        item.dataset.cheddiSeverity = call.severity;

        const header = document.createElement('div');
        header.className = 'cheddi__confirm-item-header';
        header.textContent = friendlyToolLabel(call.name);
        if (call.severity === 'destructive') {
            const badge = document.createElement('span');
            badge.className = 'badge badge-danger cheddi__confirm-severity-badge';
            badge.innerHTML = '<typo3-backend-icon identifier="actions-exclamation-triangle" size="small" aria-hidden="true"></typo3-backend-icon>';
            badge.append(ll('cheddi.confirm.destructiveBadge'));
            header.appendChild(badge);
        }
        if (Number(call.creditCost) > 0) {
            const creditBadge = document.createElement('span');
            creditBadge.className = 'cheddi__confirm-severity-badge';
            creditBadge.textContent = ll('cheddi.confirm.creditsBadge', { credits: call.creditCost });
            header.appendChild(creditBadge);
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
        argsSummary.textContent = ll('cheddi.confirm.technicalDetails');
        argsDetails.appendChild(argsSummary);
        const args = document.createElement('pre');
        args.className = 'cheddi__confirm-item-args';
        args.textContent = JSON.stringify(call.arguments ?? {}, null, 2);
        argsDetails.appendChild(args);
        item.appendChild(argsDetails);

        if (blocked) {
            item.classList.add('cheddi__confirm-item--blocked');
            const warning = document.createElement('div');
            warning.className = 'cheddi__confirm-blocked';
            warning.textContent = ll('cheddi.confirm.blockedInvalid');
            item.appendChild(warning);
        }

        const actions = document.createElement('div');
        actions.className = 'cheddi__confirm-item-actions';

        const approveBtn = document.createElement('button');
        approveBtn.type = 'button';
        approveBtn.className = 'btn btn-primary btn-sm cheddi__confirm-approve';
        approveBtn.textContent = call.severity === 'destructive'
            ? ll('cheddi.confirm.executeDestructive')
            : ll('cheddi.confirm.execute');
        if (blocked) {
            approveBtn.disabled = true;
            approveBtn.title = ll('cheddi.confirm.blockedInvalidHint');
        }
        approveBtn.addEventListener('click', () => {
            if (call.severity === 'destructive' && approveBtn.dataset.armed !== 'true') {
                approveBtn.dataset.armed = 'true';
                approveBtn.textContent = ll('cheddi.confirm.executeDestructiveArmed');
                approveBtn.classList.add('cheddi__confirm-approve--armed');
                return;
            }
            decisions.set(call.id, true);
            this.markItemDecided(item, 'approved');
            this.maybeSubmitAllDecisions(decisions, pending, container);
        });

        const declineBtn = document.createElement('button');
        declineBtn.type = 'button';
        declineBtn.className = 'btn btn-default btn-sm cheddi__confirm-decline';
        declineBtn.textContent = ll('cheddi.confirm.decline');
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
