import { ll, llOr } from '@autodudes/cheddi/i18n.js';

const CONCURRENCY = 3;

export class OpenItemFixer {
    constructor(apiClient, { preferredModel, onChange }) {
        this.api = apiClient;
        this.preferredModel = preferredModel;
        this.onChange = onChange;
        this.catalog = null;
        this.states = new Map();
        this.run = null;
    }

    async load() {
        try {
            this.catalog = await this.api.fetchOpenItemFixModels();
        } catch (e) {
            console.error('[ChEddi] could not load the suggestion models.', e);
            this.catalog = null;
        }
    }

    modelFor(kind) {
        if (kind === 'image') {
            return this.catalog?.image ?? null;
        }
        const models = this.catalog?.text ?? [];
        const preferred = this.preferredModel();
        return models.find((model) => model.name === preferred) ?? models[0] ?? null;
    }

    forget(uids) {
        const keep = new Set(uids);
        for (const uid of [...this.states.keys()]) {
            if (!keep.has(uid)) {
                this.states.delete(uid);
            }
        }
    }

    state(uid) {
        return this.states.get(uid) ?? { status: 'idle' };
    }

    setState(uid, state) {
        this.states.set(uid, state);
        this.onChange(uid);
    }

    edit(uid, value) {
        this.states.set(uid, { ...this.state(uid), value, error: '' });
    }

    select(uid, selected) {
        this.states.set(uid, { ...this.state(uid), selected });
        this.onChange(null);
    }

    pending(items) {
        return items.filter((item) => ['idle', 'error'].includes(this.state(item.uid).status));
    }

    selected(items) {
        return items.filter((item) => {
            const state = this.state(item.uid);
            return state.status === 'ready' && state.selected !== false;
        });
    }

    cancel() {
        if (this.run !== null) {
            this.run.cancelled = true;
        }
    }

    async suggestBatch(items) {
        await this.runBatch(items, false, (item) => this.suggest(item));
    }

    async acceptBatch(items) {
        return this.runBatch(items, true, (item) => this.accept(item));
    }

    async runBatch(items, accepting, step) {
        const run = { cancelled: false, done: 0, total: items.length, accepting };
        this.run = run;
        this.onChange(null);
        const queue = [...items];
        let succeeded = 0;
        const worker = async () => {
            while (queue.length > 0 && !run.cancelled) {
                if (await step(queue.shift())) {
                    succeeded += 1;
                }
                run.done += 1;
                this.onChange(null);
            }
        };
        await Promise.all(Array.from({ length: Math.min(accepting ? 1 : CONCURRENCY, items.length) }, worker));
        this.run = null;
        this.onChange(null);
        return succeeded;
    }

    async suggest(item) {
        const model = this.modelFor(item.fix)?.name ?? '';
        if (model === '') {
            this.setState(item.uid, { status: 'error', error: ll('cheddi.openItems.fix.noModel') });
            return false;
        }
        this.setState(item.uid, { status: 'loading' });
        let result = { ok: false, payload: null };
        try {
            result = await this.api.suggestOpenItemFix(item.uid, model);
        } catch (e) {
            console.error('[ChEddi] could not fetch a suggestion.', e);
        }
        const payload = result.payload ?? {};
        if (!result.ok || typeof payload.suggestion !== 'string' || payload.suggestion === '') {
            this.setState(item.uid, { status: 'error', error: this.errorText(payload) });
            return false;
        }
        this.setState(item.uid, { status: 'ready', value: payload.suggestion, model: payload.model || model, base: payload.base ?? '', selected: true, error: '' });
        return true;
    }

    async accept(item) {
        const state = this.state(item.uid);
        if (state.status !== 'ready') {
            return false;
        }
        this.setState(item.uid, { ...state, status: 'saving' });
        let result = { ok: false, payload: null };
        try {
            result = await this.api.acceptOpenItemFix(item.uid, state.value, state.model, state.base);
        } catch (e) {
            console.error('[ChEddi] could not write the suggestion.', e);
        }
        if (!result.ok) {
            this.setState(item.uid, { ...state, status: 'ready', error: this.errorText(result.payload ?? {}) });
            return false;
        }
        this.setState(item.uid, { status: 'done' });
        return true;
    }

    estimateText(kind, count) {
        const model = this.modelFor(kind);
        const tokens = this.catalog?.estimatedTokens?.[kind] ?? 0;
        if (!model || tokens === 0 || count === 0) {
            return '';
        }
        const credits = (count * tokens * model.creditsPerMillion) / 1_000_000;
        const formatted = credits.toLocaleString(document.documentElement.lang || undefined, { maximumFractionDigits: 2, minimumFractionDigits: credits < 1 ? 2 : 0 });
        return ll('cheddi.openItems.fix.estimate', { credits: formatted, count, model: model.label });
    }

    modelLabel(kind, name) {
        const model = this.modelFor(kind);
        return model?.name === name ? model.label : name;
    }

    errorText(payload) {
        const code = payload?.error?.chatErrorCode ?? '';
        const message = payload?.error?.message ?? '';
        return code !== '' ? llOr(`cheddi.error.${code}`, message || ll('cheddi.error.unknown')) : (message || ll('cheddi.error.unknown'));
    }
}
