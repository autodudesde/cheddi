import { ll } from '@autodudes/cheddi/i18n.js';

const PREVIEW_LENGTH = 90;

export class TemplatesDropdown {
    constructor(elements, { onPick, appliesToContext }) {
        this.elements = elements;
        this.onPick = onPick;
        this.appliesToContext = appliesToContext;
        this.templates = [];
        this.loaded = false;
        this.searchBound = false;
    }

    isOpen() {
        return !this.elements.templatesMenu.hidden;
    }

    toggle() {
        if (this.isOpen()) {
            this.close();
            return;
        }
        this.open();
    }

    setTemplates(templates) {
        this.templates = Array.isArray(templates) ? templates : [];
        this.loaded = true;
        this.elements.templates.hidden = this.templates.length === 0;
        if (this.templates.length === 0) {
            this.close();
            return;
        }
        if (this.isOpen()) {
            this.render();
        }
    }

    open() {
        this.bindSearch();
        this.elements.templatesMenu.hidden = false;
        this.elements.templatesToggle.setAttribute('aria-expanded', 'true');
        this.elements.templatesSearch.value = '';
        this.render();
        this.elements.templatesSearch.focus();
    }

    close() {
        this.elements.templatesMenu.hidden = true;
        this.elements.templatesToggle.setAttribute('aria-expanded', 'false');
    }

    contains(target) {
        return this.elements.templates?.contains(target) ?? false;
    }

    bindSearch() {
        if (this.searchBound) {
            return;
        }
        this.elements.templatesSearch.addEventListener('input', () => this.render());
        this.elements.templatesSearch.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.close();
                this.elements.templatesToggle.focus();
            }
        });
        this.searchBound = true;
    }

    render() {
        const list = this.elements.templatesList;
        list.replaceChildren();

        const matches = this.filtered();
        if (matches.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'cheddi__templates-empty';
            empty.textContent = ll('cheddi.templates.noMatch');
            list.appendChild(empty);
            return;
        }

        for (const template of matches) {
            list.appendChild(this.makeItem(template));
        }
    }

    filtered() {
        const needle = this.elements.templatesSearch.value.trim().toLowerCase();
        if (needle === '') {
            return this.templates;
        }

        return this.templates.filter((template) => `${template.name} ${template.prompt}`
            .toLowerCase()
            .includes(needle));
    }

    makeItem(template) {
        const item = document.createElement('li');
        item.setAttribute('role', 'none');

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'dropdown-item cheddi__templates-item';
        button.setAttribute('role', 'menuitem');

        const title = document.createElement('span');
        title.className = 'cheddi__templates-item-title';
        title.textContent = template.name;
        button.appendChild(title);

        const preview = document.createElement('span');
        preview.className = 'cheddi__templates-item-preview';
        preview.textContent = shorten(template.prompt);
        button.appendChild(preview);

        item.appendChild(button);

        if (!this.appliesToContext(template.prompt)) {
            button.disabled = true;
            button.classList.add('disabled');
            button.title = ll('cheddi.templates.needsRecord');
            return item;
        }

        button.addEventListener('click', () => {
            this.close();
            this.onPick(template.prompt);
        });

        return item;
    }
}

function shorten(prompt) {
    const flat = String(prompt).replace(/\s+/g, ' ').trim();

    return flat.length > PREVIEW_LENGTH ? `${flat.slice(0, PREVIEW_LENGTH)}…` : flat;
}
