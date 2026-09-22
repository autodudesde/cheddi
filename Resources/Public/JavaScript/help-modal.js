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

const SECTION_ICONS = {
    what: 'actions-lightbulb',
    flow: 'actions-message',
    naming: 'actions-search',
    write: 'actions-pencil',
    privacy: 'actions-shield',
    credits: 'actions-info',
    attachments: 'actions-file-add',
    limits: 'actions-exclamation-triangle',
};

export class HelpModal {
    constructor(apiClient, currentSituation = () => []) {
        this.api = apiClient;
        this.currentSituation = currentSituation;
        this.modal = null;
        this.sections = [];
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

        this.sections = sections;
        this.showOverview();
    }

    showOverview(focusIndex = null) {
        const body = this.modalBody();
        if (!body) {
            return;
        }

        body.replaceChildren();
        const situation = this.buildCurrentSituation();
        if (situation) {
            body.append(situation);
        }

        const grid = document.createElement('ul');
        grid.className = 'cheddi-help-modal__cards';
        this.sections.forEach((section, index) => grid.append(this.buildCard(section, index)));
        body.append(grid);
        body.scrollTop = 0;

        if (focusIndex !== null) {
            grid.querySelectorAll('.cheddi-help-modal__card')[focusIndex]?.focus();
        }
    }

    /**
     * What holds for this installation right now - write mode, GDPR, web research, retention - is
     * composed server-side and arrives with the status refresh. It stays above the topics and
     * outside them: it is the one part an editor has to see without looking for it.
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

    buildCard(section, index) {
        const item = document.createElement('li');
        item.className = 'cheddi-help-modal__card-item';

        const card = document.createElement('button');
        card.type = 'button';
        card.className = 'card cheddi-help-modal__card';
        card.dataset.cheddiHelpTopic = typeof section?.key === 'string' ? section.key : String(index);
        card.addEventListener('click', () => this.showTopic(index));

        const header = document.createElement('span');
        header.className = 'cheddi-help-modal__card-header';
        header.append(this.sectionIcon(section, 'medium'));
        const title = document.createElement('span');
        title.className = 'cheddi-help-modal__card-title';
        title.textContent = section?.title ?? '';
        header.append(title);
        card.append(header);

        if (typeof section?.teaser === 'string' && section.teaser !== '') {
            const teaser = document.createElement('span');
            teaser.className = 'cheddi-help-modal__card-teaser';
            teaser.textContent = section.teaser;
            card.append(teaser);
        }

        item.append(card);

        return item;
    }

    showTopic(index) {
        const body = this.modalBody();
        const section = this.sections[index];
        if (!body || !section) {
            return;
        }

        body.replaceChildren();

        const topic = document.createElement('article');
        topic.className = 'cheddi-help-modal__topic';

        const back = this.navButton(ll('cheddi.help.back'), 'actions-arrow-left', () => this.showOverview(index));
        back.classList.add('cheddi-help-modal__back');

        const heading = document.createElement('h2');
        heading.className = 'cheddi-help-modal__topic-title';
        heading.tabIndex = -1;
        heading.append(this.sectionIcon(section, 'medium'));
        const headingText = document.createElement('span');
        headingText.textContent = section.title ?? '';
        heading.append(headingText);

        topic.append(back, heading, this.buildSection(section), this.buildPager(index));
        body.append(topic);
        body.scrollTop = 0;
        heading.focus();
    }

    buildPager(index) {
        const pager = document.createElement('nav');
        pager.className = 'cheddi-help-modal__pager';
        pager.setAttribute('aria-label', ll('cheddi.help.pager'));

        const previous = this.sections[index - 1];
        if (previous) {
            const button = this.navButton(ll('cheddi.help.previous', { title: previous.title ?? '' }), 'actions-arrow-left', () => this.showTopic(index - 1));
            button.classList.add('cheddi-help-modal__previous');
            pager.append(button);
        }

        const next = this.sections[index + 1];
        if (next) {
            const button = this.navButton(ll('cheddi.help.next', { title: next.title ?? '' }), 'actions-chevron-right', () => this.showTopic(index + 1), true);
            button.classList.add('cheddi-help-modal__next');
            pager.append(button);
        }

        return pager;
    }

    navButton(text, iconIdentifier, onClick, iconAfter = false) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-default btn-sm';

        const icon = document.createElement('span');
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = `<typo3-backend-icon identifier="${iconIdentifier}" size="small"></typo3-backend-icon>`;
        const label = document.createElement('span');
        label.textContent = text;

        if (iconAfter) {
            button.append(label, icon);
        } else {
            button.append(icon, label);
        }
        button.addEventListener('click', onClick);

        return button;
    }

    sectionIcon(section, size) {
        const identifier = Object.hasOwn(SECTION_ICONS, section?.key) ? SECTION_ICONS[section.key] : 'actions-info';
        const icon = document.createElement('span');
        icon.className = 'cheddi-help-modal__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = `<typo3-backend-icon identifier="${identifier}" size="${size}"></typo3-backend-icon>`;

        return icon;
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
