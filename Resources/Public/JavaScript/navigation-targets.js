import { ll } from '@autodudes/cheddi/i18n.js';

/**
 * Opens a backend location. The content container is the normal path; a new tab is the fallback for
 * a drawer that is not running inside the backend shell.
 */
export function navigateBackend(url) {
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

export function renderNavigationTargets(container, groups, { found = false, onNavigate = null } = {}) {
    const usable = (groups ?? [])
        .filter((g) => g && Array.isArray(g.targets))
        .map((g) => ({ ...g, targets: g.targets.filter(isNavigable) }))
        .filter((g) => g.targets.length > 0);
    if (usable.length === 0) {
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'cheddi__message cheddi__message--system cheddi__nav-targets';
    if (found) {
        wrapper.classList.add('cheddi__nav-targets--found');
    }
    for (const group of usable) {
        wrapper.appendChild(buildNavigationGroup(group, found, onNavigate));
    }
    container.appendChild(wrapper);
    container.scrollTop = container.scrollHeight;
}

function buildNavigationGroup(group, found, onNavigate) {
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
        button.title = label;
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
        button.addEventListener('click', () => {
            if (target.download === true) {
                downloadFile(target.url);

                return;
            }
            if (navigateBackend(target.url) && 'function' === typeof onNavigate) {
                onNavigate();
            }
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
