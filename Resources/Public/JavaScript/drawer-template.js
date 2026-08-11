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
                aria-label="${ll('cheddi.ui.bubbleOpen', 'Open ChEddi')}">
                <img class="cheddi__bubble-icon"
                    src="${bubbleIconUrl}"
                    alt=""
                    aria-hidden="true"
                    draggable="false">
            </button>
            <section class="cheddi__drawer"
                data-cheddi-drawer-shell
                role="dialog"
                aria-label="${ll('cheddi.ui.drawerLabel', 'ChEddi')}"
                hidden>
                <div class="cheddi__resize-handle"
                    data-cheddi-resize
                    role="separator"
                    aria-orientation="horizontal"
                    aria-label="${ll('cheddi.ui.resize', 'Resize drawer')}"
                    tabindex="0"></div>
                <header class="cheddi__header">
                    <select class="cheddi__model-select"
                        data-cheddi-model-select
                        aria-label="${ll('cheddi.ui.modelSelect', 'Select ChEddi model')}"
                        hidden></select>
                    <div class="cheddi__header-credits"
                        data-cheddi-credits
                        aria-label="${ll('cheddi.ui.creditsRemaining', 'Remaining credits')}"
                        hidden>${brandIcon}<span data-cheddi-credits-value></span></div>
                    <div class="cheddi__header-actions">
                        <button type="button"
                            class="cheddi__header-button"
                            data-cheddi-actions-toggle
                            aria-label="${ll('cheddi.ui.actionsMenu', 'Actions menu')}"
                            aria-haspopup="menu"
                            aria-expanded="false">⋯</button>
                        <ul class="cheddi__actions-menu"
                            data-cheddi-actions-menu
                            role="menu"
                            hidden>
                            <li role="none">
                                <button type="button"
                                    role="menuitem"
                                    data-cheddi-new-conversation>${ll('cheddi.ui.newConversation', 'New conversation')}</button>
                            </li>
                            <li role="none">
                                <button type="button"
                                    role="menuitem"
                                    data-cheddi-open-sessions>${ll('cheddi.ui.conversationsMenu', 'Conversations …')}</button>
                            </li>
                            <li role="none">
                                <button type="button"
                                    role="menuitem"
                                    data-cheddi-open-review
                                    hidden>${ll('cheddi.review.menu', 'Changes of this conversation …')}</button>
                            </li>
                        </ul>
                    </div>
                    <button type="button"
                        class="cheddi__header-button"
                        data-cheddi-minimize
                        aria-label="${ll('cheddi.ui.minimize', 'Minimize')}"
                        title="${ll('cheddi.ui.minimize', 'Minimize')}">–</button>
                    <button type="button"
                        class="cheddi__header-button"
                        data-cheddi-close
                        aria-label="${ll('cheddi.ui.close', 'Close')}">×</button>
                </header>
                <details class="cheddi__orientation" data-cheddi-orientation hidden>
                    <summary class="cheddi__orientation-summary">${ll('cheddi.orientation.summary', 'How ChEddi works here')}</summary>
                    <ul class="cheddi__orientation-body" data-cheddi-orientation-body></ul>
                    <button type="button"
                        class="cheddi__orientation-more"
                        data-cheddi-open-help>${ll('cheddi.orientation.more', 'How ChEddi works in detail …')}</button>
                </details>
                <div class="cheddi__messages" data-cheddi-messages aria-live="polite"></div>
                <div class="cheddi__sessions-panel"
                    data-cheddi-sessions-panel
                    role="dialog"
                    aria-label="${ll('cheddi.ui.conversationsTitle', 'Conversations')}"
                    hidden>
                    <header class="cheddi__sessions-panel-header">
                        <h2 class="cheddi__sessions-panel-title">${ll('cheddi.ui.conversationsTitle', 'Conversations')}</h2>
                        <button type="button"
                            class="cheddi__header-button"
                            data-cheddi-close-sessions
                            aria-label="${ll('cheddi.ui.panelClose', 'Close panel')}">×</button>
                    </header>
                    <button type="button"
                        class="cheddi__sessions-primary"
                        data-cheddi-sessions-primary></button>
                    <ul class="cheddi__sessions-list"
                        data-cheddi-sessions-list
                        role="list"></ul>
                </div>
                <div class="cheddi__quick-actions" data-cheddi-quick-actions hidden></div>
                <div class="cheddi__attachment-chips" data-cheddi-attachment-chips hidden></div>
                <footer class="cheddi__input-area">
                    <div class="cheddi__composer">
                        <div class="cheddi__composer-resize"
                            data-cheddi-composer-resize
                            role="separator"
                            aria-orientation="horizontal"
                            aria-label="${ll('cheddi.ui.resizeComposer', 'Resize input field')}"
                            tabindex="0"></div>
                        <textarea
                            class="cheddi__textarea"
                            data-cheddi-textarea
                            rows="3"
                            placeholder="${ll('cheddi.ui.textareaPlaceholder', 'Type a question or instruction…')}"></textarea>
                    </div>
                    <div class="cheddi__input-actions">
                        <button type="button"
                            class="cheddi__attachment-button"
                            data-cheddi-attachment
                            aria-label="${ll('cheddi.attachment.add', 'Attach a document')}"
                            title="${ll('cheddi.attachment.add', 'Attach a document')}">📎</button>
                        <button type="button"
                            class="cheddi__send-button"
                            data-cheddi-send>${ll('cheddi.ui.send', 'Send')}</button>
                    </div>
                </footer>
            </section>
        `;
}
