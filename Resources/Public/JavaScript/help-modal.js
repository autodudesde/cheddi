import Modal from '@typo3/backend/modal.js';
import { ll } from '@autodudes/cheddi/i18n.js';

/**
 * Core icon per callout tone. All four identifiers ship with the backend from v12 on, which the
 * drawer is bound to — see CLAUDE.md on borrowing only what the oldest supported version has.
 */
const TONE_ICONS = {
    info: 'actions-info',
    warning: 'actions-exclamation-triangle',
    success: 'actions-check',
};

export class HelpModal {
    constructor(apiClient, currentSituation = () => []) {
        this.api = apiClient;
        this.currentSituation = currentSituation;
        this.modal = null;
    }

    open() {
        this.modal = Modal.advanced({
            title: ll('cheddi.help.modalTitle'),
            content: '',
            size: Modal.sizes.large,
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
            this.renderNotice(body, ll('cheddi.help.loadFailed'));
            return;
        }

        const sections = Array.isArray(payload?.sections) ? payload.sections : [];
        if (sections.length === 0) {
            this.renderNotice(body, ll('cheddi.help.loadFailed'));
            return;
        }

        body.replaceChildren();
        const situation = this.buildCurrentSituation();
        if (situation) {
            body.append(situation);
        }
        body.append(this.buildTabs(sections));
    }

    /**
     * What holds for this installation right now - write mode, GDPR, web research, retention - is
     * composed server-side and arrives with the status refresh. It stays above the tabs and outside
     * them: it is the one part an editor has to see without looking for it.
     */
    buildCurrentSituation() {
        const status = this.currentSituation();
        if (!Array.isArray(status) || status.length === 0) {
            return null;
        }

        const wrapper = document.createElement('section');
        wrapper.className = 'cheddi-help-modal__situation';

        const heading = document.createElement('h3');
        heading.textContent = ll('cheddi.help.currentSituation');
        wrapper.append(heading);

        for (const entry of status) {
            wrapper.append(this.buildCallout(entry));
        }

        return wrapper;
    }

    /**
     * Icon plus text, and no inner wrapper class: the wrappers the core templates put around a
     * callout's text are the part that moved between v12, v13 and v14, and `BackendDesignContractTest`
     * rejects them for that reason. `.callout` itself is a flex row in every version, so the icon and
     * a plain element line up without one.
     */
    buildCallout(entry) {
        const tone = Object.hasOwn(TONE_ICONS, entry?.tone) ? entry.tone : 'info';

        const callout = document.createElement('div');
        callout.className = `callout callout-sm callout-${tone}`;

        const icon = document.createElement('div');
        icon.className = 'callout-icon';
        icon.innerHTML = `<typo3-backend-icon identifier="${TONE_ICONS[tone]}" size="small"></typo3-backend-icon>`;

        const text = document.createElement('div');
        text.className = 'cheddi-help-modal__callout-text';
        text.textContent = entry?.text ?? '';

        callout.append(icon, text);

        return callout;
    }

    /**
     * Core classes for the look, our own click handling for the behaviour: v12 activates a tab with
     * Bootstrap (`data-bs-toggle`), v14 with its own `tab.js` (`data-typo3-tab`). Driving the
     * panels here keeps one implementation for every version instead of branching on it.
     */
    buildTabs(sections) {
        const wrapper = document.createElement('div');
        wrapper.className = 'cheddi-help-modal__tabs';

        const nav = document.createElement('ul');
        nav.className = 'nav nav-tabs';
        nav.setAttribute('role', 'tablist');

        const panes = document.createElement('div');
        panes.className = 'tab-content';

        sections.forEach((section, index) => {
            const paneId = `cheddi-help-pane-${index}`;
            const tabId = `cheddi-help-tab-${index}`;

            const item = document.createElement('li');
            item.className = 'nav-item';
            item.setAttribute('role', 'presentation');

            const button = document.createElement('button');
            button.type = 'button';
            button.className = index === 0 ? 'nav-link active' : 'nav-link';
            button.id = tabId;
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-controls', paneId);
            button.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
            button.textContent = section?.title ?? '';
            button.addEventListener('click', () => this.activateTab(wrapper, index));
            item.append(button);
            nav.append(item);

            const pane = this.buildSection(section);
            pane.id = paneId;
            pane.classList.add('tab-pane');
            pane.setAttribute('role', 'tabpanel');
            pane.setAttribute('aria-labelledby', tabId);
            if (index === 0) {
                pane.classList.add('active');
            } else {
                pane.hidden = true;
            }
            panes.append(pane);
        });

        wrapper.append(nav, panes);

        return wrapper;
    }

    activateTab(wrapper, active) {
        wrapper.querySelectorAll('.nav-link').forEach((button, index) => {
            button.classList.toggle('active', index === active);
            button.setAttribute('aria-selected', index === active ? 'true' : 'false');
        });
        wrapper.querySelectorAll('.tab-pane').forEach((pane, index) => {
            pane.classList.toggle('active', index === active);
            pane.hidden = index !== active;
        });
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

    buildSection(section) {
        const wrapper = document.createElement('section');
        wrapper.className = 'cheddi-help-modal__section';

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
            text: ll('cheddi.help.close'),
            btnClass: 'btn-default',
            name: 'close',
            trigger: (event, modal) => modal.hideModal(),
        };
    }
}
