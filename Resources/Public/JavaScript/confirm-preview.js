import { ll } from '@autodudes/cheddi/i18n.js';

export function previewHasInvalidRecords(preview) {
    return Boolean(preview)
        && Array.isArray(preview.records)
        && preview.records.some((record) => record && record.action === 'invalid');
}

export function renderPendingPreview(preview) {
    if (preview && preview.kind === 'pageTree') {
        return renderPageTree(preview);
    }

    if (!preview || preview.kind !== 'records' || !Array.isArray(preview.records) || preview.records.length === 0) {
        return null;
    }

    const container = document.createElement('div');
    container.className = 'cheddi__preview';

    for (const record of preview.records) {
        const rendered = renderRecord(record);
        if (rendered) {
            container.append(rendered);
        }
    }

    if (container.children.length === 0) {
        return null;
    }

    if (preview.truncated) {
        const more = document.createElement('div');
        more.className = 'cheddi__preview-more';
        more.textContent = ll('cheddi.preview.moreRecords', '+ {count} more record(s), see the technical details', {
            count: preview.total - preview.records.length,
        });
        container.append(more);
    }

    return container;
}

function renderPageTree(preview) {
    if (!Array.isArray(preview.pages) || preview.pages.length === 0) {
        return null;
    }

    const container = document.createElement('div');
    container.className = 'cheddi__preview';

    const item = document.createElement('div');
    item.className = 'cheddi__preview-record';

    const header = document.createElement('div');
    header.className = 'cheddi__preview-record-header';
    const badge = document.createElement('span');
    badge.className = 'cheddi__preview-action cheddi__preview-action--create';
    badge.textContent = actionLabel('create');
    header.append(badge);
    const title = document.createElement('span');
    title.className = 'cheddi__preview-title';
    title.textContent = ll('cheddi.preview.pageTree.title', '{count} new page(s)', { count: preview.total });
    header.append(title);
    item.append(header);

    if (preview.parentLabel) {
        const context = document.createElement('div');
        context.className = 'cheddi__preview-context';
        context.textContent = ll('cheddi.preview.pageTree.under', 'Below: {parent}', { parent: preview.parentLabel });
        item.append(context);
    }

    const list = document.createElement('ul');
    list.className = 'cheddi__preview-pagetree';
    for (const page of preview.pages) {
        if (!page || typeof page !== 'object') {
            continue;
        }
        const li = document.createElement('li');
        li.style.paddingInlineStart = `${Math.max(0, Number(page.depth) || 0) * 16}px`;
        li.textContent = String(page.title ?? '');
        list.append(li);
    }
    item.append(list);

    if (preview.truncated) {
        const more = document.createElement('div');
        more.className = 'cheddi__preview-more';
        more.textContent = ll('cheddi.preview.moreRecords', '+ {count} more record(s), see the technical details', {
            count: preview.total - preview.pages.length,
        });
        item.append(more);
    }

    container.append(item);

    return container;
}

function renderRecord(record) {
    if (!record || typeof record !== 'object') {
        return null;
    }

    const item = document.createElement('div');
    item.className = 'cheddi__preview-record';

    item.append(renderRecordHeader(record));

    const context = recordContext(record);
    if (context) {
        const contextEl = document.createElement('div');
        contextEl.className = 'cheddi__preview-context';
        contextEl.textContent = context;
        item.append(contextEl);
    }

    if (record.note) {
        const note = document.createElement('div');
        note.className = 'cheddi__preview-note';
        note.textContent = record.note;
        item.append(note);
    }

    if (Array.isArray(record.fields) && record.fields.length > 0) {
        item.append(renderFields(record.fields));
    }

    if (record.hiddenFieldCount > 0) {
        const more = document.createElement('div');
        more.className = 'cheddi__preview-more';
        more.textContent = ll('cheddi.preview.moreFields', '+ {count} more field(s)', {
            count: record.hiddenFieldCount,
        });
        item.append(more);
    }

    if (Array.isArray(record.translations) && record.translations.length > 0) {
        const translations = document.createElement('div');
        translations.className = 'cheddi__preview-more';
        translations.textContent = ll('cheddi.preview.translations', '+ one translation each in: {languages}', {
            languages: record.translations.join(', '),
        });
        item.append(translations);
    }

    return item;
}

function renderRecordHeader(record) {
    const header = document.createElement('div');
    header.className = 'cheddi__preview-record-header';

    const badge = document.createElement('span');
    badge.className = `cheddi__preview-action cheddi__preview-action--${record.action || 'update'}`;
    badge.textContent = actionLabel(record.action);
    header.append(badge);

    const title = document.createElement('span');
    title.className = 'cheddi__preview-title';
    title.textContent = recordTitle(record);
    header.append(title);

    return header;
}

function recordTitle(record) {
    const table = record.tableLabel || record.table || '';
    if (record.recordLabel) {
        return `${table}: ${record.recordLabel}`;
    }

    return table;
}

function recordContext(record) {
    const parts = [];

    if (record.pageLabel) {
        parts.push(ll('cheddi.preview.page', 'Page: {page}', { page: record.pageLabel }));
    }
    if (record.action === 'create' && record.position) {
        parts.push(ll('cheddi.preview.position', 'Position: {position}', { position: record.position }));
    }
    if (record.uid) {
        parts.push(`UID ${record.uid}`);
    }

    return parts.join(' · ');
}

function renderFields(fields) {
    const list = document.createElement('dl');
    list.className = 'cheddi__preview-fields';

    for (const field of fields) {
        if (!field || typeof field !== 'object') {
            continue;
        }

        const label = document.createElement('dt');
        label.textContent = field.label || field.name || '';
        list.append(label);

        const value = document.createElement('dd');
        if (field.changed === false) {
            value.append(valueEl(field.new, field.truncated), unchangedMarker());
        } else if (field.old !== null && field.old !== undefined && field.old !== '') {
            value.append(valueEl(field.old, field.truncated, 'old'), arrow(), valueEl(field.new, field.truncated, 'new'));
        } else {
            value.append(valueEl(field.new, field.truncated, 'new'));
        }
        list.append(value);
    }

    return list;
}

function valueEl(value, truncated, kind = '') {
    const el = document.createElement('span');
    el.className = kind ? `cheddi__preview-value cheddi__preview-value--${kind}` : 'cheddi__preview-value';

    const text = String(value ?? '');
    if (text === '') {
        el.classList.add('cheddi__preview-value--empty');
        el.textContent = ll('cheddi.preview.empty', '(empty)');

        return el;
    }

    el.textContent = truncated ? `${text}…` : text;

    return el;
}

function unchangedMarker() {
    const el = document.createElement('span');
    el.className = 'cheddi__preview-unchanged';
    el.textContent = ll('cheddi.preview.unchanged', 'unchanged');

    return el;
}

function arrow() {
    const el = document.createElement('span');
    el.className = 'cheddi__preview-arrow';
    el.textContent = '→';
    el.setAttribute('aria-label', ll('cheddi.preview.becomes', 'becomes'));

    return el;
}

function actionLabel(action) {
    const labels = {
        create: ll('cheddi.preview.action.create', 'New'),
        update: ll('cheddi.preview.action.update', 'Change'),
        delete: ll('cheddi.preview.action.delete', 'Delete'),
        copy: ll('cheddi.preview.action.copy', 'Copy'),
        move: ll('cheddi.preview.action.move', 'Move'),
        localize: ll('cheddi.preview.action.localize', 'Translate'),
        skipped: ll('cheddi.preview.action.skipped', 'Skipped'),
        invalid: ll('cheddi.preview.action.invalid', 'Invalid'),
    };

    return labels[action] || labels.update;
}
