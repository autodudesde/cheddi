import { ll } from '@autodudes/cheddi/i18n.js';

/**
 * Composed by ChatOrientationService::statements(); the drawer only renders it. A client that
 * rebuilt the sentences kept a fourth copy of the write-mode rule and drifted onto a mode the
 * server had removed.
 */
export function orientationStatus(orientation) {
    const statements = orientation?.statements;
    return Array.isArray(statements) ? statements : [];
}

export function renderIntro(elements, { orientation, visible, activeModelLabel = '' }) {
    const intro = elements.intro;
    if (!intro) {
        return;
    }
    if (!visible) {
        intro.hidden = true;
        return;
    }

    const modelLine = elements.introModel;
    if (modelLine) {
        modelLine.textContent = activeModelLabel === ''
            ? ''
            : ll('cheddi.notice.activeModel', { model: activeModelLabel });
        modelLine.hidden = activeModelLabel === '';
    }

    const list = elements.introList;
    list.replaceChildren();
    for (const statement of orientationStatus(orientation)) {
        const item = document.createElement('li');
        item.textContent = statement.text;
        list.append(item);
    }

    intro.hidden = false;
}
