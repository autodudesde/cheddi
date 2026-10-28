import { ll } from '@autodudes/cheddi/i18n.js';

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
