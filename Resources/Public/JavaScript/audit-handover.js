const PREFILL_EVENT = 'cheddi:prefill';

const buttons = document.querySelectorAll('[data-audit-finding-action="cheddi-fix"]');
const drawerAvailable = top.document.querySelector('[data-cheddi-drawer]') !== null;

buttons.forEach((button) => {
    if (!drawerAvailable) {
        button.remove();
        return;
    }
    button.addEventListener('click', () => {
        top.document.dispatchEvent(new CustomEvent(PREFILL_EVENT, { detail: { text: button.dataset.auditFindingPayload ?? '' } }));
    });
});
