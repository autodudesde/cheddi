import Modal from '@typo3/backend/modal.js';
import { ll } from '@autodudes/cheddi/i18n.js';

export class HelpModal {
    constructor(apiClient) {
        this.api = apiClient;
        this.modal = null;
    }

    open() {
        this.modal = Modal.advanced({
            title: ll('cheddi.help.modalTitle', 'How ChEddi works'),
            content: '',
            size: Modal.sizes.medium,
            additionalCssClasses: ['cheddi-help-modal'],
            buttons: [this.closeButton()],
        });

        this.modal.addEventListener('typo3-modal-shown', () => this.refresh());
        this.modal.addEventListener('typo3-modal-hidden', () => {
            this.modal = null;
        });
    }

    async refresh() {
        const body = this.modalBody();
        if (!body) {
            return;
        }

        let payload;
        try {
            payload = await this.api.fetchHelp();
        } catch (err) {
            console.error('[ChEddi] could not load the help text.', err);
            this.renderNotice(body, ll('cheddi.help.loadFailed', 'The help text could not be loaded.'));
            return;
        }

        const sections = Array.isArray(payload?.sections) ? payload.sections : [];
        if (sections.length === 0) {
            this.renderNotice(body, ll('cheddi.help.loadFailed', 'The help text could not be loaded.'));
            return;
        }

        this.renderSections(body, sections);
    }

    modalBody() {
        return this.modal ? this.modal.querySelector('.t3js-modal-body') : null;
    }

    renderNotice(body, message) {
        body.replaceChildren();
        const notice = document.createElement('p');
        notice.textContent = message;
        body.append(notice);
    }

    renderSections(body, sections) {
        body.replaceChildren();
        for (const section of sections) {
            body.append(this.buildSection(section));
        }
    }

    buildSection(section) {
        const wrapper = document.createElement('section');
        wrapper.className = 'cheddi-help-modal__section';

        const heading = document.createElement('h3');
        heading.textContent = section?.title ?? '';
        wrapper.append(heading);

        let list = null;
        for (const block of Array.isArray(section?.blocks) ? section.blocks : []) {
            if (block?.type === 'item') {
                if (list === null) {
                    list = document.createElement('ul');
                    wrapper.append(list);
                }
                const item = document.createElement('li');
                item.textContent = block.text ?? '';
                list.append(item);
                continue;
            }
            list = null;
            const paragraph = document.createElement('p');
            paragraph.textContent = block?.text ?? '';
            wrapper.append(paragraph);
        }

        return wrapper;
    }

    closeButton() {
        return {
            text: ll('cheddi.help.close', 'Close'),
            btnClass: 'btn-default',
            name: 'close',
            trigger: (event, modal) => modal.hideModal(),
        };
    }
}
