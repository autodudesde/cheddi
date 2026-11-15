export const componentName = 'cheddi-module';

class CheddiModuleElement extends HTMLElement {
    static get observedAttributes() {
        return ['endpoint', 'active'];
    }

    constructor() {
        super();
        this.moduleState = null;
        this.router = null;
        this.drawer = null;
        this.notice = null;
        this.boundTrackerLoaded = () => this.reportTitleAfterTracker();
    }

    connectedCallback() {
        this.router = this.closest('typo3-backend-module-router');
        this.router?.addEventListener('typo3-iframe-loaded', this.boundTrackerLoaded);
        this.loadDrawer();
        this.showNotice();
    }

    hasMountPoint() {
        return document.querySelector('[data-cheddi-drawer]') !== null;
    }

    async loadDrawer() {
        if (!this.hasMountPoint()) {
            return;
        }
        const { drawer } = await import('@autodudes/cheddi/chat-drawer.js');
        this.drawer = drawer;
        this.syncDrawer();
    }

    showNotice() {
        const endpoint = this.getAttribute('endpoint');
        if (this.hasMountPoint() || !endpoint || !this.isConnected) {
            return;
        }
        if (this.notice === null) {
            this.notice = document.createElement('iframe');
            this.notice.title = 'ChEddi';
            Object.assign(this.notice.style, { position: 'absolute', inset: '0', width: '100%', height: '100%', border: '0' });
            Object.assign(this.style, { display: 'block', position: 'relative' });
            this.appendChild(this.notice);
        }
        this.notice.src = endpoint;
    }

    disconnectedCallback() {
        this.router?.removeEventListener('typo3-iframe-loaded', this.boundTrackerLoaded);
    }

    attributeChangedCallback(name, oldValue, value) {
        if (name === 'active') {
            this.syncDrawer();
            return;
        }
        if (!value) {
            return;
        }
        this.moduleState = { url: value, title: 'ChEddi', module: 'web_cheddi' };
        this.report('typo3-module-load');
        this.report('typo3-module-loaded');
        this.showNotice();
    }

    syncDrawer() {
        if (this.drawer === null) {
            return;
        }
        if (this.hasAttribute('active')) {
            this.drawer.enterModule(this);
        } else {
            this.drawer.leaveModule();
        }
    }

    // The state tracker frame is an empty document, its load resets the browser title.
    reportTitleAfterTracker() {
        if (this.hasAttribute('active') && this.moduleState !== null) {
            this.report('typo3-module-loaded');
        }
    }

    report(eventName) {
        this.dispatchEvent(new CustomEvent(eventName, { detail: this.moduleState, bubbles: true, composed: true }));
    }
}

customElements.define(componentName, CheddiModuleElement);
