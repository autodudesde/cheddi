import MultiStepWizard from '@typo3/backend/multi-step-wizard.js';

export function slideElement(slide) {
    return typeof slide?.get === 'function' ? slide.get(0) : slide;
}

export async function resetWizard() {
    const previous = MultiStepWizard.setup?.$carousel?.get(0)?.closest('typo3-backend-modal');
    if (previous?.isConnected) {
        // The hidden event of the previous wizard resets the setup, also one already filled for the next
        await new Promise((resolve) => previous.addEventListener('typo3-modal-hidden', resolve, { once: true }));
    }
    MultiStepWizard.setup = { slides: [], settings: {}, forceSelection: false, $carousel: null, carousel: null };
}
