import { readTurnContext } from '@autodudes/cheddi/backend-context.js';

const FORM_HEADERS = { 'Content-Type': 'application/x-www-form-urlencoded' };
const NO_STORE = { cache: 'no-store', headers: { 'Cache-Control': 'no-store' } };

function formEntries(values, prefix = '') {
    return Object.entries(values).flatMap(([key, value]) => {
        const name = prefix === '' ? key : `${prefix}[${key}]`;

        return value !== null && typeof value === 'object'
            ? formEntries(value, name)
            : [[name, String(value ?? '')]];
    });
}

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
            throw new ChatTransportError(
                payload?.error?.chatErrorCode ?? '',
                payload?.error?.message ?? '',
            );
        }
        return payload;
    }

    async fetchStatus({ refreshCredits = false } = {}, signal) {
        const response = await this.request(this.ajaxUrl('cheddi_status'), {
            method: 'POST',
            headers: { ...FORM_HEADERS, 'Cache-Control': 'no-store' },
            body: new URLSearchParams({ refreshCredits: refreshCredits ? '1' : '0' }),
            credentials: 'same-origin',
            signal,
            cache: 'no-store',
        });
        return response.json();
    }

    async fetchSessions() {
        const url = this.optionalUrl('cheddi_sessions_list');
        if (!url) {
            return [];
        }
        const response = await this.request(url, { credentials: 'same-origin', ...NO_STORE });
        if (!response.ok) {
            return [];
        }
        const payload = await response.json();
        return Array.isArray(payload?.sessions) ? payload.sessions : [];
    }

    async fetchPageTitle(pageId) {
        const base = this.optionalUrl('cheddi_page_title');
        if (!base) {
            return null;
        }
        const url = new URL(base, window.location.origin);
        url.searchParams.set('pageId', String(pageId));
        const response = await this.request(url.pathname + url.search, { credentials: 'same-origin', ...NO_STORE });
        if (!response.ok) {
            return null;
        }
        const payload = await response.json();
        return typeof payload?.title === 'string' ? payload.title : null;
    }

    async fetchPageGuidelines(pageId) {
        const base = this.optionalUrl('cheddi_page_guidelines');
        if (!base) {
            return null;
        }
        const url = new URL(base, window.location.origin);
        url.searchParams.set('pageId', String(pageId));
        const response = await this.request(url.pathname + url.search, { credentials: 'same-origin', ...NO_STORE });
        return response.ok ? response.json() : null;
    }

    async fetchOpenItems() {
        const url = this.optionalUrl('cheddi_open_items');
        if (!url) {
            return null;
        }
        const response = await this.request(url, { credentials: 'same-origin', ...NO_STORE });
        return response.ok ? response.json() : null;
    }

    async fetchOpenItemRules() {
        const url = this.optionalUrl('cheddi_open_item_rules');
        if (!url) {
            return null;
        }
        const response = await this.request(url, { credentials: 'same-origin', ...NO_STORE });
        return response.ok ? response.json() : null;
    }

    async fetchOpenItemRuleOptions(params) {
        const base = this.optionalUrl('cheddi_open_item_rule_options');
        if (!base) {
            return null;
        }
        const url = new URL(base, window.location.origin);
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
        }
        const response = await this.request(url.pathname + url.search, { credentials: 'same-origin', ...NO_STORE });
        return response.ok ? response.json() : null;
    }

    async fetchOpenItemRuleGoalOptions(params) {
        const base = this.optionalUrl('cheddi_open_item_rule_goal_options');
        if (!base) {
            return null;
        }
        const url = new URL(base, window.location.origin);
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
        }
        const response = await this.request(url.pathname + url.search, { credentials: 'same-origin', ...NO_STORE });
        return response.ok ? response.json() : null;
    }

    async previewOpenItemRuleGoal(values) {
        return this.postOpenItemRule('cheddi_open_item_rule_goal_preview', values);
    }

    async createOpenItemRuleFromGoal(values) {
        return this.postOpenItemRule('cheddi_open_item_rule_goal_create', values);
    }

    async previewOpenItemRule(values) {
        return this.postOpenItemRule('cheddi_open_item_rule_preview', values);
    }

    async fetchOpenItemRuleSuggestions() {
        const url = this.optionalUrl('cheddi_open_item_rule_suggestions');
        if (!url) {
            return null;
        }
        const response = await this.request(url, { credentials: 'same-origin', ...NO_STORE });
        return response.ok ? response.json() : null;
    }

    async adoptOpenItemRules(ids) {
        return this.postOpenItemRule('cheddi_open_item_rule_adopt', { ids });
    }

    async saveOpenItemRule(values) {
        return this.postOpenItemRule('cheddi_open_item_rule_save', values);
    }

    async toggleOpenItemRule(uid, active) {
        return this.postOpenItemRule('cheddi_open_item_rule_toggle', { uid, active: active ? 1 : 0 });
    }

    async deleteOpenItemRule(uid) {
        return this.postOpenItemRule('cheddi_open_item_rule_delete', { uid });
    }

    async postOpenItemRule(routeKey, values) {
        const url = this.optionalUrl(routeKey);
        if (!url) {
            return { ok: false, payload: null };
        }
        const response = await this.request(url, {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams(formEntries(values)),
            credentials: 'same-origin',
        });
        return { ok: response.ok, payload: await response.json().catch(() => null) };
    }

    async followWorkspace(sessionUuid, workspaceChoice) {
        const url = this.optionalUrl('cheddi_ws_follow');
        if (!url) {
            return null;
        }
        const response = await this.request(url, {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({ session: sessionUuid ?? '', workspace: workspaceChoice ?? '' }),
            credentials: 'same-origin',
        });
        return response.ok ? response.json() : null;
    }

    async resolveOpenItem(uid) {
        return (await this.postOpenItemRule('cheddi_open_item_resolve', { uid })).ok;
    }

    async fetchOpenItemFixModels() {
        const url = this.optionalUrl('cheddi_open_item_fix_models');
        if (!url) {
            return null;
        }
        const response = await this.request(url, { credentials: 'same-origin' });
        return response.ok ? response.json() : null;
    }

    async suggestOpenItemFix(uid, model) {
        return this.postOpenItemRule('cheddi_open_item_suggest', { uid, model });
    }

    async acceptOpenItemFix(uid, value, model, base) {
        return this.postOpenItemRule('cheddi_open_item_accept', { uid, value, model, base });
    }

    csvDownloadUrl(sessionUuid, callId) {
        const base = this.optionalUrl('cheddi_download_csv');
        if (!base || !sessionUuid || !callId) {
            return null;
        }
        const url = new URL(base, window.location.origin);
        url.searchParams.set('sessionUuid', sessionUuid);
        url.searchParams.set('callId', callId);
        return url.pathname + url.search;
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

    async renameSession(sessionUuid, title) {
        const url = this.optionalUrl('cheddi_session_rename');
        if (!url) {
            return false;
        }
        const response = await this.request(url, {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({ sessionUuid, title }),
            credentials: 'same-origin',
        });
        return response.ok;
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

    async fetchTurnProgress(sessionUuid, signal) {
        const url = this.optionalUrl('cheddi_turn_progress');
        if (!url) {
            return null;
        }
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: FORM_HEADERS,
                body: new URLSearchParams({ sessionUuid: sessionUuid ?? '' }),
                signal,
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return null;
            }
            const payload = await response.json();
            return payload?.progress ?? null;
        } catch (err) {
            // A failing poll must never surface as a turn error; the turn request itself reports the truth.
            return null;
        }
    }

    async startTurn({ sessionUuid, text, model, workspace = '', attachments = [], retry = false }, signal) {
        const response = await this.request(this.ajaxUrl('cheddi_turn'), {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({
                sessionUuid: sessionUuid ?? '',
                text,
                model,
                workspace,
                context: JSON.stringify(readTurnContext()),
                attachments: JSON.stringify(attachments.map((a) => a.uid)),
                retry: retry ? '1' : '0',
            }),
            signal,
            credentials: 'same-origin',
        });
        return this.parseResponse(response);
    }

    async summarizeHistory({ sessionUuid }, signal) {
        const response = await this.request(this.ajaxUrl('cheddi_summarize'), {
            method: 'POST',
            headers: FORM_HEADERS,
            body: new URLSearchParams({ sessionUuid: sessionUuid ?? '' }),
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
                context: JSON.stringify(readTurnContext()),
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
