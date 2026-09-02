import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { ll } from '@autodudes/cheddi/i18n.js';

const WORKSPACES_MODULE = 'workspaces_publish';

export class WorkspaceReview {
    constructor(apiClient) {
        this.api = apiClient;
        this.modal = null;
        this.sessionUuid = null;
    }

    async open(sessionUuid) {
        this.sessionUuid = sessionUuid;

        this.modal = Modal.advanced({
            title: ll('cheddi.review.title'),
            content: '',
            size: Modal.sizes.large,
            additionalCssClasses: ['cheddi-review-modal'],
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
            payload = await this.api.fetchWorkspaceChanges(this.sessionUuid);
        } catch (err) {
            console.error('[ChEddi] could not load workspace changes.', err);
            this.renderNotice(body, ll('cheddi.review.loadFailed'));
            return;
        }

        if (!payload || payload.workspacesAvailable === false) {
            this.renderNotice(body, ll('cheddi.review.unavailable'));
            return;
        }

        const changes = Array.isArray(payload.changes) ? payload.changes : [];
        if (changes.length === 0) {
            this.renderNotice(body, ll('cheddi.review.empty'));
            return;
        }

        this.renderChanges(body, changes);
    }

    modalBody() {
        return this.modal ? this.modal.querySelector('.t3js-modal-body') : null;
    }

    renderNotice(body, message) {
        body.replaceChildren();
        this.renderFooter([]);
        const notice = document.createElement('p');
        notice.textContent = message;
        body.append(notice, this.moduleLink());
    }

    renderChanges(body, changes) {
        body.replaceChildren();
        this.renderFooter(changes);

        const intro = document.createElement('p');
        intro.textContent = ll('cheddi.review.intro');
        body.append(intro);

        const wrapper = document.createElement('div');
        wrapper.className = 'table-fit';
        wrapper.append(this.buildTable(changes));
        body.append(wrapper);

        body.append(this.moduleLink());
    }

    closeButton() {
        return {
            text: ll('cheddi.review.close'),
            btnClass: 'btn-default',
            name: 'close',
            trigger: (event, modal) => modal.hideModal(),
        };
    }

    renderFooter(changes) {
        if (!this.modal) {
            return;
        }

        if (changes.length === 0) {
            this.modal.buttons = [this.closeButton()];

            return;
        }

        const uids = changes.map((change) => change.uid);

        this.modal.buttons = [
            this.closeButton(),
            {
                text: ll('cheddi.review.discardAll'),
                btnClass: 'btn-default',
                name: 'discard-all',
                trigger: () => this.confirmDiscard(uids),
            },
            {
                text: ll('cheddi.review.publishAll'),
                btnClass: 'btn-primary',
                name: 'publish-all',
                trigger: () => this.apply(uids, true),
            },
        ];
    }

    buildTable(changes) {
        const table = document.createElement('table');
        table.className = 'table table-striped table-hover';

        const tbody = document.createElement('tbody');
        for (const change of changes) {
            tbody.append(this.buildRow(change));
        }
        table.append(tbody);

        return table;
    }

    buildRow(change) {
        const row = document.createElement('tr');

        const label = document.createElement('td');
        const title = document.createElement('div');
        title.textContent = change.label || `${change.table}:${change.recordUid}`;
        label.append(title);

        if (Array.isArray(change.changedFields) && change.changedFields.length > 0) {
            const fields = document.createElement('small');
            fields.className = 'text-body-secondary';
            fields.textContent = ll('cheddi.review.changedFields', {
                fields: change.changedFields.join(', '),
            });
            label.append(fields);
        }
        row.append(label);

        const status = document.createElement('td');
        status.textContent = this.statusLabel(change);
        row.append(status);

        const actions = document.createElement('td');
        actions.className = 'text-end';
        actions.append(
            this.actionButton(ll('cheddi.review.publish'), 'btn-primary', () => this.apply([change.uid], true)),
            this.actionButton(ll('cheddi.review.discard'), 'btn-default', () => this.confirmDiscard([change.uid])),
        );
        row.append(actions);

        return row;
    }

    actionButton(text, btnClass, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-sm ${btnClass} ms-1`;
        button.textContent = text;
        button.addEventListener('click', onClick);
        return button;
    }

    statusLabel(change) {
        switch (change.status) {
            case 'added':
                return ll('cheddi.review.statusAdded');
            case 'removed':
                return ll('cheddi.review.statusDeleted');
            default:
                return ll('cheddi.review.statusModified');
        }
    }

    confirmDiscard(uids) {
        const confirmation = Modal.confirm(
            ll('cheddi.review.confirmDiscardTitle'),
            ll('cheddi.review.confirmDiscardText'),
            SeverityEnum.warning,
        );
        confirmation.addEventListener('confirm.button.ok', () => {
            confirmation.hideModal();
            this.apply(uids, false);
        });
        confirmation.addEventListener('confirm.button.cancel', () => confirmation.hideModal());
    }

    async apply(uids, publish) {
        let payload;
        try {
            payload = await this.api.applyWorkspaceChanges({ sessionUuid: this.sessionUuid, uids, publish });
        } catch (err) {
            console.error('[ChEddi] could not apply workspace changes.', err);
            Notification.error(ll('cheddi.review.loadFailed'), '');
            return;
        }

        const applied = Array.isArray(payload?.applied) ? payload.applied.length : 0;
        const errors = Array.isArray(payload?.errors) ? payload.errors : [];

        if (applied > 0) {
            const message = publish
                ? ll('cheddi.review.published', { count: applied })
                : ll('cheddi.review.discarded', { count: applied });
            Notification.success(message, '');
        }
        if (errors.length > 0) {
            Notification.error(ll('cheddi.review.partialFailure', { count: errors.length }), errors[0]);
        }

        await this.refresh();
    }

    moduleLink() {
        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'btn btn-link btn-sm ps-0';
        link.textContent = ll('cheddi.review.openModule');
        link.addEventListener('click', () => {
            try {
                top.TYPO3.ModuleMenu.App.showModule(WORKSPACES_MODULE);
                this.modal?.hideModal();
            } catch (err) {
                console.error('[ChEddi] could not open the Workspaces module.', err);
            }
        });
        return link;
    }
}
