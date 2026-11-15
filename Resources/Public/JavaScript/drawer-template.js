import { ll } from '@autodudes/cheddi/i18n.js';

export function drawerMarkup({ bubbleIconUrl, brandIconUrl }) {
    const brandIcon = brandIconUrl
        ? `<img class="cheddi__header-credits-icon"
                    src="${brandIconUrl}"
                    alt=""
                    aria-hidden="true"
                    draggable="false">`
        : '';

    return `
            <button type="button"
                class="cheddi__bubble"
                data-cheddi-bubble
                aria-label="${ll('cheddi.ui.bubbleOpen')}"
                title="${ll('cheddi.ui.bubbleOpen')}">
                <img class="cheddi__bubble-icon"
                    src="${bubbleIconUrl}"
                    alt=""
                    aria-hidden="true"
                    draggable="false">
                <span class="badge cheddi__open-items-badge" data-cheddi-open-items-badge hidden></span>
            </button>
            <section class="cheddi__drawer"
                data-cheddi-drawer-shell
                role="dialog"
                aria-label="${ll('cheddi.ui.drawerLabel')}"
                hidden>
                <div class="cheddi__resize-handle"
                    data-cheddi-resize
                    role="separator"
                    aria-orientation="horizontal"
                    aria-label="${ll('cheddi.ui.resize')}"
                    tabindex="0"></div>
                <header class="cheddi__header">
                    <div class="cheddi__header-row cheddi__header-row--status">
                        <div class="cheddi__status" data-cheddi-status>
                            <span class="badge cheddi__status-badge cheddi__status-badge--mode"
                                data-cheddi-workspace
                                hidden><span class="cheddi__status-label" data-cheddi-workspace-label></span><span class="cheddi__status-value" data-cheddi-workspace-title></span></span>
                            <span class="badge cheddi__status-badge cheddi__status-badge--credits"
                                data-cheddi-credits
                                hidden>${brandIcon}<span class="cheddi__status-value" data-cheddi-credits-value></span></span>
                        </div>
                        <div class="cheddi__header-actions">
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-open-help
                                aria-label="${ll('cheddi.ui.helpButton')}"
                                title="${ll('cheddi.ui.helpButton')}"><typo3-backend-icon identifier="actions-info" size="small" aria-hidden="true"></typo3-backend-icon></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button cheddi__open-items-button"
                                data-cheddi-open-open-items
                                aria-label="${ll('cheddi.openItems.title')}"
                                title="${ll('cheddi.openItems.title')}"
                                hidden><typo3-backend-icon identifier="actions-lightbulb" size="small" aria-hidden="true"></typo3-backend-icon><span class="cheddi__open-items-count" data-cheddi-open-items-count hidden></span></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-open-sessions
                                aria-label="${ll('cheddi.ui.conversationsMenu')}"
                                title="${ll('cheddi.ui.conversationsMenu')}"><typo3-backend-icon identifier="actions-message" size="small" aria-hidden="true"></typo3-backend-icon></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-open-review
                                aria-label="${ll('cheddi.review.menu')}"
                                title="${ll('cheddi.review.menu')}"
                                hidden><typo3-backend-icon identifier="apps-toolbar-menu-workspace" size="small" aria-hidden="true"></typo3-backend-icon></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-dock-toggle
                                aria-label="${ll('cheddi.ui.dock')}"
                                title="${ll('cheddi.ui.dock')}"><typo3-backend-icon identifier="actions-arrow-right" size="small" aria-hidden="true"></typo3-backend-icon></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-maximize
                                aria-label="${ll('cheddi.ui.maximize')}"
                                title="${ll('cheddi.ui.maximize')}"><typo3-backend-icon identifier="actions-fullscreen" size="small" aria-hidden="true"></typo3-backend-icon></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-minimize
                                aria-label="${ll('cheddi.ui.minimize')}"
                                title="${ll('cheddi.ui.minimize')}"><typo3-backend-icon identifier="actions-minus" size="small" aria-hidden="true"></typo3-backend-icon></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-close
                                aria-label="${ll('cheddi.ui.close')}"
                                title="${ll('cheddi.ui.close')}"><typo3-backend-icon identifier="actions-close" size="small" aria-hidden="true"></typo3-backend-icon></button>
                        </div>
                    </div>
                    <div class="cheddi__header-row cheddi__header-row--model">
                        <select class="form-select form-select-sm cheddi__workspace-select"
                            data-cheddi-workspace-select
                            aria-label="${ll('cheddi.ui.workspaceSelect')}"
                            hidden></select>
                        <select class="form-select form-select-sm cheddi__model-select"
                            data-cheddi-model-select
                            aria-label="${ll('cheddi.ui.modelSelect')}"
                            hidden></select>
                    </div>
                </header>
                <div class="cheddi__intro" data-cheddi-intro hidden>
                    <p class="cheddi__intro-title">${ll('cheddi.intro.title')}</p>
                    <p class="cheddi__intro-model" data-cheddi-intro-model hidden></p>
                    <ul class="cheddi__intro-list" data-cheddi-intro-list></ul>
                </div>
                <div class="cheddi__messages" data-cheddi-messages aria-live="polite"></div>
                <div class="cheddi__open-items-panel cheddi__panel"
                    data-cheddi-open-items-panel
                    role="dialog"
                    aria-label="${ll('cheddi.openItems.title')}"
                    hidden>
                    <header class="cheddi__panel-header">
                        <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-open-items-back
                                aria-label="${ll('cheddi.openItems.back')}"
                                title="${ll('cheddi.openItems.back')}" hidden><typo3-backend-icon identifier="actions-arrow-left" size="small" aria-hidden="true"></typo3-backend-icon></button>
                        <h2 class="cheddi__panel-title" data-cheddi-open-items-title>${ll('cheddi.openItems.title')}</h2>
                        <div class="cheddi__panel-actions">
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__panel-action cheddi__panel-action--collapsible"
                                data-cheddi-manage-open-items
                                title="${ll('cheddi.openItems.manage')}"
                                hidden><typo3-backend-icon identifier="actions-cog" size="small" aria-hidden="true"></typo3-backend-icon><span class="cheddi__panel-action-label">${ll('cheddi.openItems.manage')}</span></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-close-open-items
                                aria-label="${ll('cheddi.ui.panelClose')}"
                                title="${ll('cheddi.ui.panelClose')}"><typo3-backend-icon identifier="actions-close" size="small" aria-hidden="true"></typo3-backend-icon></button>
                        </div>
                    </header>
                    <ul class="list-group cheddi__open-items-list" data-cheddi-open-items-list role="list"></ul>
                    <div class="cheddi__panel-footer" data-cheddi-open-items-footer hidden></div>
                </div>
                <div class="cheddi__open-items-panel cheddi__open-item-rules-panel cheddi__panel"
                    data-cheddi-open-item-rules-panel
                    role="dialog"
                    aria-label="${ll('cheddi.openItemRule.ui.title')}"
                    hidden>
                    <header class="cheddi__panel-header">
                        <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-back-open-item-rules
                                aria-label="${ll('cheddi.openItems.back')}"
                                title="${ll('cheddi.openItems.back')}"><typo3-backend-icon identifier="actions-arrow-left" size="small" aria-hidden="true"></typo3-backend-icon></button>
                        <h2 class="cheddi__panel-title">${ll('cheddi.openItemRule.ui.title')}</h2>
                        <div class="cheddi__panel-actions">
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__panel-action"
                                data-cheddi-new-open-item-rule
                                title="${ll('cheddi.openItemRule.suggest.open')}"><typo3-backend-icon identifier="actions-lightbulb" size="small" aria-hidden="true"></typo3-backend-icon><span class="cheddi__panel-action-label">${ll('cheddi.openItemRule.suggest.open')}</span></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__panel-action cheddi__panel-action--collapsible"
                                data-cheddi-custom-open-item-rule
                                title="${ll('cheddi.openItemRule.goal.new')}"><typo3-backend-icon identifier="actions-plus" size="small" aria-hidden="true"></typo3-backend-icon><span class="cheddi__panel-action-label">${ll('cheddi.openItemRule.goal.new')}</span></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-close-open-item-rules
                                aria-label="${ll('cheddi.ui.panelClose')}"
                                title="${ll('cheddi.ui.panelClose')}"><typo3-backend-icon identifier="actions-close" size="small" aria-hidden="true"></typo3-backend-icon></button>
                        </div>
                    </header>
                    <ul class="list-group cheddi__open-items-list" data-cheddi-open-item-rules-list role="list"></ul>
                </div>
                <div class="cheddi__sessions-panel cheddi__panel"
                    data-cheddi-sessions-panel
                    role="dialog"
                    aria-label="${ll('cheddi.ui.conversationsTitle')}"
                    hidden>
                    <header class="cheddi__panel-header cheddi__sessions-panel-header">
                        <h2 class="cheddi__panel-title">${ll('cheddi.ui.conversationsTitle')}</h2>
                        <div class="cheddi__panel-actions">
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-sessions-new
                                aria-label="${ll('cheddi.ui.newConversation')}"
                                title="${ll('cheddi.ui.newConversation')}"><typo3-backend-icon identifier="actions-plus" size="small" aria-hidden="true"></typo3-backend-icon></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-close-sessions
                                aria-label="${ll('cheddi.ui.panelClose')}"
                                title="${ll('cheddi.ui.panelClose')}"><typo3-backend-icon identifier="actions-close" size="small" aria-hidden="true"></typo3-backend-icon></button>
                        </div>
                    </header>
                    <div class="cheddi__sessions-body">
                        <input type="search"
                            class="form-control form-control-sm cheddi__sessions-search"
                            data-cheddi-sessions-search
                            autocomplete="off"
                            placeholder="${ll('cheddi.sessions.searchPlaceholder')}"
                            aria-label="${ll('cheddi.sessions.searchPlaceholder')}"
                            hidden>
                        <ul class="list-group cheddi__sessions-list"
                            data-cheddi-sessions-list
                            role="list"></ul>
                    </div>
                </div>
                <div class="callout callout-warning cheddi__context-notice" data-cheddi-context-notice role="status" hidden>
                    <span class="cheddi__context-notice-text" data-cheddi-context-notice-text></span>
                    <button type="button"
                        class="btn btn-default btn-sm cheddi__context-notice-action"
                        data-cheddi-summarize>${ll('cheddi.context.summarizeButton')}</button>
                </div>
                <footer class="cheddi__input-area">
                    <div class="cheddi__composer">
                        <div class="cheddi__composer-resize"
                            data-cheddi-composer-resize
                            role="separator"
                            aria-orientation="horizontal"
                            aria-label="${ll('cheddi.ui.resizeComposer')}"
                            tabindex="0"></div>
                        <div class="cheddi__attachment-chips" data-cheddi-attachment-chips hidden></div>
                        <div class="cheddi__composer-field">
                            <textarea
                                class="form-control cheddi__textarea"
                                data-cheddi-textarea
                                rows="3"
                                placeholder="${ll('cheddi.ui.textareaPlaceholder')}"></textarea>
                        </div>
                        <div class="cheddi__composer-toolbar">
                            <div class="cheddi__composer-tools">
                                <button type="button"
                                    class="btn btn-default btn-sm cheddi__tool-button"
                                    data-cheddi-attachment
                                    aria-label="${ll('cheddi.attachment.add')}"
                                    title="${ll('cheddi.attachment.add')}"><typo3-backend-icon identifier="actions-file-add" size="small" aria-hidden="true"></typo3-backend-icon></button>
                                <button type="button"
                                    class="btn btn-default btn-sm cheddi__tool-button"
                                    data-cheddi-current-page
                                    aria-label="${ll('cheddi.ui.currentPage')}"
                                    title="${ll('cheddi.ui.currentPageHint')}"><typo3-backend-icon identifier="actions-page-open" size="small" aria-hidden="true"></typo3-backend-icon><span class="cheddi__tool-button-label">${ll('cheddi.ui.currentPage')}</span></button>
                                <div class="cheddi__templates" data-cheddi-templates hidden>
                                    <button type="button"
                                        class="btn btn-default btn-sm cheddi__tool-button"
                                        data-cheddi-templates-toggle
                                        aria-haspopup="menu"
                                        aria-expanded="false"
                                        title="${ll('cheddi.ui.templatesMenu')}"><typo3-backend-icon identifier="actions-template" size="small" aria-hidden="true"></typo3-backend-icon><span class="cheddi__tool-button-label">${ll('cheddi.ui.templatesMenu')}</span></button>
                                    <div class="dropdown-menu cheddi__templates-menu"
                                        data-cheddi-templates-menu
                                        role="menu"
                                        aria-label="${ll('cheddi.ui.templatesTitle')}"
                                        hidden>
                                        <input type="search"
                                            class="form-control form-control-sm cheddi__templates-search"
                                            data-cheddi-templates-search
                                            autocomplete="off"
                                            placeholder="${ll('cheddi.templates.searchPlaceholder')}"
                                            aria-label="${ll('cheddi.templates.searchPlaceholder')}">
                                        <ul class="cheddi__templates-list"
                                            data-cheddi-templates-list
                                            role="list"></ul>
                                    </div>
                                </div>
                                <button type="button"
                                    class="btn btn-default btn-sm cheddi__tool-button"
                                    data-cheddi-guidelines
                                    aria-label="${ll('cheddi.ui.guidelines')}"
                                    title="${ll('cheddi.ui.guidelinesHint')}"><typo3-backend-icon identifier="actions-book" size="small" aria-hidden="true"></typo3-backend-icon><span class="cheddi__tool-button-label">${ll('cheddi.ui.guidelines')}</span></button>
                            </div>
                            <button type="button"
                                class="btn btn-primary btn-sm cheddi__send-button"
                                data-cheddi-send>${ll('cheddi.ui.send')}</button>
                        </div>
                    </div>
                </footer>
            </section>
        `;
}
