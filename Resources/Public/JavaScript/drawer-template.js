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
                aria-label="${ll('cheddi.ui.bubbleOpen')}">
                <img class="cheddi__bubble-icon"
                    src="${bubbleIconUrl}"
                    alt=""
                    aria-hidden="true"
                    draggable="false">
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
                            <div class="cheddi__header-menu">
                                <button type="button"
                                    class="btn btn-default btn-sm cheddi__header-button"
                                    data-cheddi-actions-toggle
                                    aria-label="${ll('cheddi.ui.actionsMenu')}"
                                    aria-haspopup="menu"
                                    aria-expanded="false"><typo3-backend-icon identifier="actions-menu-alternative" size="small" aria-hidden="true"></typo3-backend-icon></button>
                                <ul class="dropdown-menu cheddi__actions-menu"
                                    data-cheddi-actions-menu
                                    role="menu"
                                    hidden>
                                    <li role="none">
                                        <button type="button"
                                            class="dropdown-item"
                                            role="menuitem"
                                            data-cheddi-new-conversation>${ll('cheddi.ui.newConversation')}</button>
                                    </li>
                                    <li role="none">
                                        <button type="button"
                                            class="dropdown-item"
                                            role="menuitem"
                                            data-cheddi-open-sessions>${ll('cheddi.ui.conversationsMenu')}</button>
                                    </li>
                                    <li role="none">
                                        <button type="button"
                                            class="dropdown-item"
                                            role="menuitem"
                                            data-cheddi-open-review
                                            hidden>${ll('cheddi.review.menu')}</button>
                                    </li>
                                </ul>
                            </div>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-minimize
                                aria-label="${ll('cheddi.ui.minimize')}"
                                title="${ll('cheddi.ui.minimize')}"><typo3-backend-icon identifier="actions-minus" size="small" aria-hidden="true"></typo3-backend-icon></button>
                            <button type="button"
                                class="btn btn-default btn-sm cheddi__header-button"
                                data-cheddi-close
                                aria-label="${ll('cheddi.ui.close')}"><typo3-backend-icon identifier="actions-close" size="small" aria-hidden="true"></typo3-backend-icon></button>
                        </div>
                    </div>
                    <div class="cheddi__header-row cheddi__header-row--model">
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
                <div class="cheddi__sessions-panel"
                    data-cheddi-sessions-panel
                    role="dialog"
                    aria-label="${ll('cheddi.ui.conversationsTitle')}"
                    hidden>
                    <header class="cheddi__sessions-panel-header">
                        <h2 class="cheddi__sessions-panel-title">${ll('cheddi.ui.conversationsTitle')}</h2>
                        <button type="button"
                            class="btn btn-default btn-sm cheddi__header-button"
                            data-cheddi-close-sessions
                            aria-label="${ll('cheddi.ui.panelClose')}"><typo3-backend-icon identifier="actions-close" size="small" aria-hidden="true"></typo3-backend-icon></button>
                    </header>
                    <button type="button"
                        class="btn btn-default btn-sm cheddi__sessions-primary"
                        data-cheddi-sessions-primary></button>
                    <ul class="list-group cheddi__sessions-list"
                        data-cheddi-sessions-list
                        role="list"></ul>
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
                                    disabled
                                    aria-label="${ll('cheddi.ui.guidelines')}"
                                    title="${ll('cheddi.ui.guidelinesSoon')}"><typo3-backend-icon identifier="actions-book" size="small" aria-hidden="true"></typo3-backend-icon><span class="cheddi__tool-button-label">${ll('cheddi.ui.guidelines')}</span></button>
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
