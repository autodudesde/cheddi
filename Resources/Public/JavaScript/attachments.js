import { ll } from '@autodudes/cheddi/i18n.js';

export const MAX_ATTACHMENTS = 5;

const ACCEPTED = '.txt,.json,.xml,.pdf,.docx,.doc,.odt,.rtf,.xlsx,.xls,.ods';

export class AttachmentTray {
    constructor(apiClient, chipsElement, onChange) {
        this.api = apiClient;
        this.element = chipsElement;
        this.onChange = onChange ?? (() => {});
        this.attachments = [];
        this.input = this.createFileInput();
    }

    createFileInput() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = ACCEPTED;
        input.multiple = true;
        input.hidden = true;
        input.addEventListener('change', () => {
            const files = Array.from(input.files ?? []);
            input.value = '';
            void this.addFiles(files);
        });
        this.element.append(input);
        return input;
    }

    pick() {
        if (this.attachments.length >= MAX_ATTACHMENTS) {
            this.notify(ll('cheddi.attachment.tooMany', 'You can attach at most {count} files.', { count: MAX_ATTACHMENTS }));
            return;
        }
        this.input.click();
    }

    async addFiles(files) {
        for (const file of files) {
            if (this.attachments.length >= MAX_ATTACHMENTS) {
                this.notify(ll('cheddi.attachment.tooMany', 'You can attach at most {count} files.', { count: MAX_ATTACHMENTS }));
                break;
            }

            const placeholder = { uid: 0, name: file.name, uploading: true, readable: true, reason: '' };
            this.attachments.push(placeholder);
            this.render();

            try {
                const payload = await this.api.uploadAttachment(file);
                Object.assign(placeholder, payload.attachment, { uploading: false });
            } catch (err) {
                console.error('[ChEddi] attachment upload failed.', err);
                this.remove(placeholder);
                this.notify(ll('cheddi.attachment.uploadFailed', 'Could not upload "{name}".', { name: file.name }));
            }

            this.render();
        }
    }

    remove(attachment) {
        this.attachments = this.attachments.filter((a) => a !== attachment);
        this.render();
    }

    clear() {
        this.attachments = [];
        this.render();
    }

    /**
     * Only uploaded, readable-or-not attachments are sent; a failed upload never
     * reaches the turn. Unreadable files are still sent so the model can say so.
     */
    pending() {
        return this.attachments.filter((a) => !a.uploading && a.uid > 0);
    }

    render() {
        for (const chip of Array.from(this.element.querySelectorAll('[data-cheddi-chip]'))) {
            chip.remove();
        }

        for (const attachment of this.attachments) {
            this.element.append(this.buildChip(attachment));
        }

        this.element.hidden = this.attachments.length === 0;
        this.onChange();
    }

    buildChip(attachment) {
        const chip = document.createElement('span');
        chip.className = 'cheddi__chip';
        chip.dataset.cheddiChip = '';

        const label = document.createElement('span');
        label.className = 'cheddi__chip-label';
        label.textContent = attachment.name;
        chip.append(label);

        const hint = this.hintFor(attachment);
        if (hint !== '') {
            chip.classList.add('cheddi__chip--warning');
            const note = document.createElement('small');
            note.className = 'cheddi__chip-hint';
            note.textContent = hint;
            chip.append(note);
        }

        if (!attachment.uploading) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'cheddi__chip-remove';
            remove.setAttribute('aria-label', ll('cheddi.attachment.remove', 'Remove attachment'));
            remove.textContent = '×';
            remove.addEventListener('click', () => this.remove(attachment));
            chip.append(remove);
        }

        return chip;
    }

    hintFor(attachment) {
        if (attachment.uploading) {
            return ll('cheddi.attachment.uploading', 'Uploading …');
        }
        if (attachment.readable) {
            return '';
        }
        switch (attachment.reason) {
            case 'oversize':
                return ll('cheddi.attachment.oversize', 'Too large to read');
            case 'libraryMissing':
                return ll('cheddi.attachment.libraryMissing', 'This format cannot be read on this installation');
            case 'notFound':
                return ll('cheddi.attachment.notFound', 'File not found');
            default:
                return ll('cheddi.attachment.metadataOnly', 'Text cannot be read from this file');
        }
    }

    notify(message) {
        console.warn(`[ChEddi] ${message}`);
        this.element.dispatchEvent(new CustomEvent('cheddi:attachment-notice', { bubbles: true, detail: { message } }));
    }
}
