import { ll } from '@autodudes/cheddi/i18n.js';

export class AttachmentTray {
    constructor(apiClient, chipsElement, onChange) {
        this.api = apiClient;
        this.element = chipsElement;
        this.onChange = onChange ?? (() => {});
        this.attachments = [];
        this.limits = null;
        this.input = this.createFileInput();
    }

    applyLimits(limits) {
        this.limits = limits ?? null;
        if (typeof this.limits?.accept === 'string' && this.limits.accept !== '') {
            this.input.accept = this.limits.accept;
        }
    }

    maxAttachments() {
        const max = Number(this.limits?.maxPerMessage);
        return Number.isFinite(max) && max > 0 ? max : null;
    }

    isFull() {
        const max = this.maxAttachments();
        return max !== null && this.attachments.length >= max;
    }

    createFileInput() {
        const input = document.createElement('input');
        input.type = 'file';
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

    bindDropTarget(target, isLocked) {
        const carriesFiles = (event) => Array.from(event.dataTransfer?.types ?? []).includes('Files');
        const setActive = (active) => target.classList.toggle('cheddi__drawer--drop-target', active);

        target.addEventListener('dragover', (event) => {
            if (!carriesFiles(event)) {
                return;
            }
            event.preventDefault();
            event.dataTransfer.dropEffect = isLocked() ? 'none' : 'copy';
            setActive(!isLocked());
        });
        target.addEventListener('dragleave', (event) => {
            if (!target.contains(event.relatedTarget)) {
                setActive(false);
            }
        });
        target.addEventListener('drop', (event) => {
            if (!carriesFiles(event)) {
                return;
            }
            event.preventDefault();
            setActive(false);
            if (!isLocked()) {
                void this.addFiles(Array.from(event.dataTransfer.files));
            }
        });
    }

    bindPaste(textarea) {
        textarea.addEventListener('paste', (event) => {
            const files = Array.from(event.clipboardData?.files ?? []);
            // Office copies carry the text and a picture of it; the text is what was meant.
            if (files.length === 0 || event.clipboardData.getData('text/plain') !== '') {
                return;
            }
            event.preventDefault();
            void this.addFiles(files);
        });
    }

    pick() {
        if (this.isFull()) {
            this.notify(ll('cheddi.attachment.tooMany', { count: this.maxAttachments() }));
            return;
        }
        this.input.click();
    }

    async addFiles(files) {
        for (const file of files) {
            if (this.isFull()) {
                this.notify(ll('cheddi.attachment.tooMany', { count: this.maxAttachments() }));
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
                this.notify(ll('cheddi.attachment.uploadFailed', { name: file.name }));
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
        chip.className = 'badge cheddi__chip';
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
            remove.className = 'btn btn-default btn-sm cheddi__chip-remove';
            remove.setAttribute('aria-label', ll('cheddi.attachment.remove'));
            remove.innerHTML = '<typo3-backend-icon identifier="actions-close" size="small" aria-hidden="true"></typo3-backend-icon>';
            remove.addEventListener('click', () => this.remove(attachment));
            chip.append(remove);
        }

        return chip;
    }

    hintFor(attachment) {
        if (attachment.uploading) {
            return ll('cheddi.attachment.uploading');
        }
        if (attachment.readable) {
            return '';
        }
        switch (attachment.reason) {
            case 'oversize':
                return ll('cheddi.attachment.oversize');
            case 'libraryMissing':
                return ll('cheddi.attachment.libraryMissing');
            case 'phpExtensionMissing':
                return ll('cheddi.attachment.phpExtensionMissing');
            case 'notFound':
                return ll('cheddi.attachment.notFound');
            default:
                return ll('cheddi.attachment.metadataOnly');
        }
    }

    notify(message) {
        console.warn(`[ChEddi] ${message}`);
        this.element.dispatchEvent(new CustomEvent('cheddi:attachment-notice', { bubbles: true, detail: { message } }));
    }
}
