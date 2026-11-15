import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { currentPageId } from '@autodudes/cheddi/backend-context.js';
import { ll } from '@autodudes/cheddi/i18n.js';
import { navigateBackend } from '@autodudes/cheddi/navigation-targets.js';

function paragraph(text) {
    const element = document.createElement('p');
    element.textContent = text;
    return element;
}

function renderSections(body, sections) {
    body.appendChild(paragraph(ll('cheddi.guidelines.intro')));
    for (const section of sections) {
        const heading = document.createElement('h3');
        heading.textContent = section.label;
        const text = document.createElement('div');
        text.className = 'cheddi-guidelines-modal__text';
        text.textContent = section.text;
        body.append(heading, text);
    }
}

function renderManageButton(body, url, modal, onNavigate) {
    const footer = modal.querySelector('.modal-footer');
    if (!footer || footer.querySelector('[data-cheddi-guidelines-manage]')) {
        return;
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-default cheddi-guidelines-modal__manage';
    button.dataset.cheddiGuidelinesManage = '';
    button.textContent = ll('cheddi.guidelines.manage');
    button.addEventListener('click', () => {
        modal.hideModal();
        if (navigateBackend(url)) {
            onNavigate();
        }
    });
    footer.prepend(button);
}

async function fill(api, pageId, modal, onNavigate) {
    const body = modal.querySelector('.t3js-modal-body');
    if (!body) {
        return;
    }

    let payload = null;
    try {
        payload = await api.fetchPageGuidelines(pageId);
    } catch (err) {
        console.error('[ChEddi] could not load the editorial rules.', err);
    }

    body.replaceChildren();
    if (payload === null) {
        body.appendChild(paragraph(ll('cheddi.guidelines.loadFailed')));
        return;
    }

    if (!payload.resolved) {
        body.appendChild(paragraph(ll('cheddi.guidelines.unknown')));
    } else if (payload.sections.length === 0) {
        body.appendChild(paragraph(ll('cheddi.guidelines.empty')));
    } else {
        renderSections(body, payload.sections);
    }

    if (typeof payload.manageUrl === 'string' && payload.manageUrl !== '') {
        renderManageButton(body, payload.manageUrl, modal, onNavigate);
    }
}

export function openGuidelines(api, onNavigate) {
    const pageId = currentPageId();
    if (pageId === null) {
        Notification.warning(ll('cheddi.ui.noCurrentPage'), '');
        return;
    }

    const modal = Modal.advanced({
        title: ll('cheddi.guidelines.title'),
        content: '',
        size: Modal.sizes.large,
        additionalCssClasses: ['cheddi-guidelines-modal'],
        buttons: [{
            text: ll('cheddi.help.close'),
            btnClass: 'btn-default',
            name: 'close',
            trigger: (event, shown) => shown.hideModal(),
        }],
    });
    modal.addEventListener('typo3-modal-shown', () => fill(api, pageId, modal, onNavigate));
}
