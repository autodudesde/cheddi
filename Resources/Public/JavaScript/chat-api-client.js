import { readBackendContext } from '@autodudes/cheddi/backend-context.js';

const FORM_HEADERS = { 'Content-Type': 'application/x-www-form-urlencoded' };

export class ChatTransportError extends Error {
    constructor(chatErrorCode, message) {
        super(message);
        this.name = 'ChatTransportError';
        this.chatErrorCode = chatErrorCode;
    }
}

export class ChatApiClient {
    ajaxUrl(name) {
        const url = (typeof TYPO3 !== 'undefined') ? TYPO3?.settings?.ajaxUrls?.[name] : undefined;
        if (!url) {
            throw new Error(`ChEddi: AJAX route "${name}" is not registered.`);
        }
        return url;
    }

    optionalUrl(name) {
        const url = (typeof TYPO3 !== 'undefined') ? TYPO3?.settings?.ajaxUrls?.[name] : undefined;
        return typeof url === 'string' ? url : null;
    }

    async request(url, options) {
        try {
            return await fetch(url, options);
        } catch (err) {
            if (err?.name === 'AbortError') {
                throw err;
            }
            console.error('[ChEddi] chat server did not respond.', url, err);
            throw new ChatTransportError('serverUnreachable', err?.message ?? 'Request failed.');
        }
    }

    async parseResponse(response) {
        const text = await response.text();
        let payload;
        try {
            payload = JSON.parse(text);
        } catch (err) {
            console.error(`[ChEddi] chat server returned non-JSON (HTTP ${response.status}).`, text, err);
            throw new ChatTransportError('serverError', `Chat server returned non-JSON (HTTP ${response.status}).`);
        }
        if (!response.ok && payload?.status !== 'error') {
            throw new Error(payload?.error?.message ?? `Chat server returned HTTP ${response.status}.`);
        }
        return payload;
    }

    async fetchModels() {
        const response = await this.request(this.ajaxUrl('cheddi_models'), { method: 'POST', credentials: 'same-origin' });
        return response.json();
    }

    async fetchTemplates() {
        const url = this.optionalUrl('cheddi_templates');
        if (!url) {
            return [];
        }
        const response = await this.request(url, { method: 'POST', credentials: 'same-origin' });
        const payload = await response.json();
        return Array.isArray(payload?.templates) ? payload.templates : [];
    }

    async fetchSessions() {
        const url = this.optionalUrl('cheddi_sessions_list');
        if (!url) {
            return [];
        }
        const response = await this.request(url, { credentials: 'same-origin' });
        if (!response.ok) {
            return [];
        }
        const payload = await response.json();
        return Array.isArray(payload?.sessions) ? payload.sessions : [];
    }

    async loadSession(sessionUuid) {
        const url = this.optionalUrl('cheddi_session_load');
        if (!url) {
            return null;
        }
        const response = await this.request(url, {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({ sessionUuid }),
            credentials: 'same-origin',
        });
        if (!response.ok) {
            return null;
        }
        const payload = await response.json();
        return payload?.session ?? null;
    }

    async deleteSession(sessionUuid) {
        const url = this.optionalUrl('cheddi_session_delete');
        if (!url) {
            return false;
        }
        const response = await this.request(url, {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({ sessionUuid }),
            credentials: 'same-origin',
        });
        return response.ok;
    }

    async fetchHelp() {
        const url = this.optionalUrl('cheddi_help');
        if (!url) {
            return null;
        }
        const response = await this.request(url, { method: 'POST', credentials: 'same-origin' });
        return this.parseResponse(response);
    }

    async fetchWorkspaceChanges(sessionUuid) {
        const url = this.optionalUrl('cheddi_ws_changes');
        if (!url) {
            return null;
        }
        const response = await this.request(url, {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({ sessionUuid }),
            credentials: 'same-origin',
        });
        return this.parseResponse(response);
    }

    async applyWorkspaceChanges({ sessionUuid, uids, publish }) {
        const route = publish ? 'cheddi_ws_publish' : 'cheddi_ws_discard';
        const response = await this.request(this.ajaxUrl(route), {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({
                sessionUuid,
                uids: JSON.stringify(uids),
            }),
            credentials: 'same-origin',
        });
        return this.parseResponse(response);
    }

    async uploadAttachment(file) {
        const body = new FormData();
        body.append('file', file);
        const response = await this.request(this.ajaxUrl('cheddi_attachment_upload'), {
            method: 'POST',
            body,
            credentials: 'same-origin',
        });
        return this.parseResponse(response);
    }

    async preflightAttachments(uids) {
        const response = await this.request(this.ajaxUrl('cheddi_attachment_preflight'), {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({ attachments: JSON.stringify(uids) }),
            credentials: 'same-origin',
        });
        return this.parseResponse(response);
    }

    async startTurn({ sessionUuid, text, model, attachments = [] }, signal) {
        const response = await this.request(this.ajaxUrl('cheddi_turn'), {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({
                sessionUuid: sessionUuid ?? '',
                text,
                model,
                context: JSON.stringify(readBackendContext()),
                attachments: JSON.stringify(attachments.map((a) => a.uid)),
            }),
            signal,
            credentials: 'same-origin',
        });
        return this.parseResponse(response);
    }

    async continueTurn({ sessionUuid }, signal) {
        const response = await this.request(this.ajaxUrl('cheddi_turn_continue'), {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({
                sessionUuid: sessionUuid ?? '',
                context: JSON.stringify(readBackendContext()),
            }),
            signal,
            credentials: 'same-origin',
        });
        return this.parseResponse(response);
    }

    async submitConfirmations({ sessionUuid, approvals }, signal) {
        const response = await this.request(this.ajaxUrl('cheddi_confirm'), {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({
                sessionUuid: sessionUuid ?? '',
                approvals: JSON.stringify(approvals),
            }),
            signal,
            credentials: 'same-origin',
        });
        return this.parseResponse(response);
    }
}
