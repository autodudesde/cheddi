import { ll } from '@autodudes/cheddi/i18n.js';

const CREDITS_WARNING_THRESHOLD = 50;

export function renderModeBadge(elements, orientation) {
    const badge = elements.workspace;
    if (!badge || !orientation) {
        return;
    }
    const live = orientation.writeMode === 'live';
    const title = typeof orientation.workspaceTitle === 'string' ? orientation.workspaceTitle : '';

    badge.classList.toggle('cheddi__status-badge--live', live);
    if (live) {
        elements.workspaceLabel.textContent = '';
        elements.workspaceLabel.hidden = true;
        elements.workspaceTitle.textContent = ll('cheddi.ui.modeLive');
        describe(badge, ll('cheddi.ui.modeLiveHint'));
        badge.hidden = false;
        return;
    }

    if (orientation.workspacePending) {
        elements.workspaceLabel.textContent = '';
        elements.workspaceLabel.hidden = true;
        elements.workspaceTitle.textContent = ll('cheddi.ui.workspacePending');
        describe(badge, ll('cheddi.ui.workspacePendingHint'));
        badge.hidden = false;
        return;
    }

    elements.workspaceLabel.hidden = false;
    elements.workspaceLabel.textContent = ll('cheddi.ui.workspaceBadgeLabel');
    elements.workspaceTitle.textContent = title !== ''
        ? title
        : ll('cheddi.ui.workspaceUnnamed');
    describe(badge, title !== ''
        ? ll('cheddi.ui.workspaceBadgeHint', { workspace: title, id: Number(orientation.workspaceId) || 0 })
        : ll('cheddi.orientation.writeWorkspace'));
    badge.hidden = false;
}

// The word labels are hidden by the stylesheet on a narrow drawer, so digits are never truncated.
export function renderCreditsBadge(elements, credits) {
    const badge = elements.credits;
    if (!badge) {
        return;
    }
    const pack = numberOrNull(credits?.pack);
    const planTotal = numberOrNull(credits?.planTotal);
    const planUsed = numberOrNull(credits?.planUsed);
    if (pack === null && planTotal === null) {
        badge.hidden = true;
        return;
    }

    const parts = [];
    const hints = [];
    if (pack !== null) {
        parts.push(creditsPart(ll('cheddi.credits.pack'), format(pack)));
        hints.push(ll('cheddi.credits.packHint', { count: format(pack) }));
    }
    if (planTotal !== null) {
        parts.push(creditsPart(
            ll('cheddi.credits.plan'),
            `${format(planUsed ?? 0)}/${format(planTotal)}`,
        ));
        hints.push(ll('cheddi.credits.planHint', {
            used: format(planUsed ?? 0),
            total: format(planTotal),
        }));
    }

    elements.creditsValue.replaceChildren(...parts);
    describe(badge, hints.join(' · '));
    badge.classList.toggle(
        'cheddi__status-badge--low',
        pack !== null && pack < CREDITS_WARNING_THRESHOLD && !planHasRoom(planUsed, planTotal),
    );
    badge.hidden = false;
}

function describe(badge, text) {
    badge.title = text;
    badge.setAttribute('aria-label', text);
}

function planHasRoom(planUsed, planTotal) {
    return planTotal !== null && planTotal - (planUsed ?? 0) > 0;
}

function creditsPart(label, value) {
    const part = document.createElement('span');
    part.className = 'cheddi__credits-part';

    const labelNode = document.createElement('span');
    labelNode.className = 'cheddi__status-label';
    labelNode.textContent = label;
    part.append(labelNode);

    const valueNode = document.createElement('span');
    valueNode.className = 'cheddi__credits-number';
    valueNode.textContent = value;
    part.append(valueNode);

    return part;
}

function numberOrNull(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function format(value) {
    return Number(value).toLocaleString();
}
