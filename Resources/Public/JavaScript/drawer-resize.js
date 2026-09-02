import { clampComposerHeight, clampSize, saveState } from '@autodudes/cheddi/state.js';

/**
 * Pointer-drag resizing for the drawer and for the composer. Pure DOM work with no knowledge of the
 * conversation, which is why it takes the elements and the persisted size rather than the drawer.
 */
export function bindComposerResize(elements, state, onComposerHeightChanged) {
    const handle = elements.composerResizeHandle;
    const textarea = elements.textarea;
    if (!handle) {
        return;
    }

    const onPointerDown = (event) => {
        if (event.button !== undefined && event.button !== 0) {
            return;
        }
        event.preventDefault();
        const startY = event.clientY;
        const startHeight = textarea.offsetHeight;
        const pointerId = event.pointerId;

        try {
            handle.setPointerCapture(pointerId);
        } catch (err) {
            console.debug('[ChEddi] setPointerCapture failed (pointer gone).', err);
        }
        document.body.classList.add('cheddi-resizing-composer');

        const onPointerMove = (moveEvent) => {
            const proposedHeight = startHeight + (startY - moveEvent.clientY);
            const height = clampComposerHeight(proposedHeight, state.height);
            state.composerHeight = height;
            textarea.style.height = `${height}px`;
        };

        const onPointerUp = () => {
            handle.removeEventListener('pointermove', onPointerMove);
            handle.removeEventListener('pointerup', onPointerUp);
            handle.removeEventListener('pointercancel', onPointerUp);
            try {
                handle.releasePointerCapture(pointerId);
            } catch (err) {
                console.debug('[ChEddi] releasePointerCapture failed (already released).', err);
            }
            document.body.classList.remove('cheddi-resizing-composer');
            saveState(state);
        };

        handle.addEventListener('pointermove', onPointerMove);
        handle.addEventListener('pointerup', onPointerUp);
        handle.addEventListener('pointercancel', onPointerUp);
    };

    handle.addEventListener('pointerdown', onPointerDown);
}

export function bindResize(elements, state) {
    const handle = elements.resizeHandle;
    const drawer = elements.drawer;

    const onPointerDown = (event) => {
        if (event.button !== undefined && event.button !== 0) {
            return;
        }
        event.preventDefault();
        const startX = event.clientX;
        const startY = event.clientY;
        const startWidth = drawer.offsetWidth;
        const startHeight = drawer.offsetHeight;
        const pointerId = event.pointerId;

        try {
            handle.setPointerCapture(pointerId);
        } catch (err) {
            console.debug('[ChEddi] setPointerCapture failed (pointer gone).', err);
        }
        document.body.classList.add('cheddi-resizing');

        const onPointerMove = (moveEvent) => {
            const proposedWidth = startWidth + (startX - moveEvent.clientX);
            const proposedHeight = startHeight + (startY - moveEvent.clientY);
            const { width, height } = clampSize(proposedWidth, proposedHeight);
            state.width = width;
            state.height = height;
            drawer.style.width = `${width}px`;
            drawer.style.height = `${height}px`;
        };

        const onPointerUp = () => {
            handle.removeEventListener('pointermove', onPointerMove);
            handle.removeEventListener('pointerup', onPointerUp);
            handle.removeEventListener('pointercancel', onPointerUp);
            try {
                handle.releasePointerCapture(pointerId);
            } catch (err) {
                console.debug('[ChEddi] releasePointerCapture failed (already released).', err);
            }
            document.body.classList.remove('cheddi-resizing');
            // A shorter drawer lowers the composer's ceiling, so re-clamp it.
            onComposerHeightChanged();
            saveState(state);
        };

        handle.addEventListener('pointermove', onPointerMove);
        handle.addEventListener('pointerup', onPointerUp);
        handle.addEventListener('pointercancel', onPointerUp);
    };

    handle.addEventListener('pointerdown', onPointerDown);
}
