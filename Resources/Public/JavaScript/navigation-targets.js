import ClientRequest from '@typo3/backend/event/client-request.js';
import { ll } from '@autodudes/cheddi/i18n.js';

export async function navigateInChatWorkspace(url, followWorkspace, onNavigate) {
    const container = top?.TYPO3?.Backend?.ContentContainer;
    if (!container?.beforeSetUrl) {
        if (navigateBackend(url)) {
            onNavigate();
        }
        return;
    }

    const interaction = new ClientRequest('typo3.setUrl', null);
    try {
        await container.beforeSetUrl(interaction);
    } catch {
        // The editor kept an unsaved form open, so neither the workspace nor the view may change
        return;
    }
    await followWorkspace();
    container.setUrl(url, interaction);
    onNavigate();
}

export function navigateBackend(url) {
    try {
        if (top?.TYPO3?.Backend?.ContentContainer?.setUrl) {
            top.TYPO3.Backend.ContentContainer.setUrl(url);
            return true;
        }
    } catch {
        // Falls through to a new tab
    }
    try {
        window.open(url, '_blank', 'noopener');
        return true;
    } catch (e) {
        console.error('[ChEddi] could not open the backend location.', e);
        return false;
    }
}

export function downloadFile(url) {
    const link = document.createElement('a');
    link.href = url;
    link.download = '';
    link.rel = 'noopener';
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
}

const DANGEROUS_SCHEME =/^\s*(javascript|data|vbscript|file|blob):/i;

function isNavigable(target) {
    return Boolean(target)
        && typeof target.url === 'string'
        && target.url !== ''
        && !DANGEROUS_SCHEME.test(target.url);
}

export function mergeNavigationGroups(existing, additional) {
    const merged = new Map();
    for (const group of [...(existing ?? []), ...(additional ?? [])]) {
        if (!group || !Array.isArray(group.targets)) {
            continue;
        }
        const current = merged.get(group.table);
        if (!current) {
            merged.set(group.table, { ...group, targets: [...group.targets] });
            continue;
        }
        current.omitted = (current.omitted ?? 0) + (group.omitted ?? 0);
        for (const target of group.targets) {
            if (!current.targets.some((known) => known.url === target.url)) {
                current.targets.push(target);
            }
        }
    }
    return [...merged.values()];
}

const FOUND_INLINE_LIMIT = 6;

export function renderNavigationTargets(container, groups, { found = false, onNavigate = () => {}, followWorkspace = async () => {} } = {}) {
    const usable = (groups ?? [])
        .filter((g) => g && Array.isArray(g.targets))
        .map((g) => ({ ...g, targets: g.targets.filter(isNavigable) }))
        .filter((g) => g.targets.length > 0);
    if (usable.length === 0) {
        return;
    }

    const languages = new Set(usable.flatMap((g) => g.targets.map((t) => t.language).filter(Boolean)));
    const showLanguage = languages.size > 1;

    const wrapper = document.createElement('div');
    wrapper.className = 'cheddi__message cheddi__message--system cheddi__nav-targets';
    let groupHost = wrapper;
    if (found) {
        wrapper.classList.add('cheddi__nav-targets--found');
        const count = usable.reduce((sum, g) => sum + g.targets.length + (g.omitted ?? 0), 0);
        if (count > FOUND_INLINE_LIMIT) {
            const details = document.createElement('details');
            details.className = 'cheddi__nav-found-details';
            const summary = document.createElement('summary');
            summary.textContent = ll('cheddi.notice.navFoundSummary', { count: String(count) });
            details.appendChild(summary);
            wrapper.appendChild(details);
            groupHost = details;
        }
    }
    for (const group of usable) {
        groupHost.appendChild(buildNavigationGroup(group, found, onNavigate, followWorkspace, showLanguage));
    }
    container.appendChild(wrapper);
    container.scrollTop = container.scrollHeight;
}

function buildNavigationGroup(group, found, onNavigate, followWorkspace, showLanguage) {
    const row = document.createElement('div');
    row.className = 'cheddi__nav-group';

    const heading = document.createElement('span');
    heading.className = 'cheddi__nav-heading';
    heading.textContent = found
        ? ll('cheddi.notice.navHeadingFound', { table: group.label || ll('cheddi.notice.navLinkDefault') })
        : group.label || ll('cheddi.notice.navHeading');
    row.appendChild(heading);

    for (const target of group.targets) {
        const label = target.label || ll('cheddi.notice.navLinkDefault');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-default btn-sm cheddi__nav-link';
        button.title = target.language && showLanguage ? `${label} (${target.language})` : label;
        const icon = document.createElement('span');
        icon.className = 'cheddi__nav-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<typo3-backend-icon identifier="actions-open" size="small" aria-hidden="true"></typo3-backend-icon>';
        const text = document.createElement('span');
        text.className = 'cheddi__nav-label';
        text.textContent = label;
        if (target.download === true) {
            icon.innerHTML = '<typo3-backend-icon identifier="actions-download" size="small" aria-hidden="true"></typo3-backend-icon>';
        }
        button.append(icon, text);
        if (target.language && showLanguage) {
            const language = document.createElement('span');
            language.className = 'badge badge-default cheddi__nav-language';
            language.textContent = target.language;
            button.append(language);
        }
        button.addEventListener('click', () => {
            if (target.download === true) {
                downloadFile(target.url);

                return;
            }
            navigateInChatWorkspace(target.url, followWorkspace, onNavigate);
        });
        row.appendChild(button);
    }

    if (group.omitted > 0) {
        const more = document.createElement('span');
        more.className = 'cheddi__nav-more';
        more.textContent = ll('cheddi.notice.navMore', { count: String(group.omitted) });
        row.appendChild(more);
    }

    return row;
}
