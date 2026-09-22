const STORAGE_KEY = 'cheddi.drawerState';
const SESSION_STORAGE_KEY = 'cheddi.sessionUuid';
const SESSION_MODEL_STORAGE_KEY = 'cheddi.sessionModel';

const SIZE_LIMITS = {
    minWidth: 360,
    minHeight: 480,
    maxWidthPx: 760,
    maxHeightPx: 1000,
    maxWidthVw: 0.9,
    maxHeightVh: 0.9,
};

const COMPOSER_LIMITS = {
    minHeight: 72,
    maxHeightPx: 320,
    maxHeightRatio: 0.5,
};

const DEFAULT_STATE = {
    open: false,
    docked: false,
    width: 480,
    height: 720,
    composerHeight: 80,
};

const MIN_CONTENT_WIDTH = 640;

export function loadState() {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return { ...DEFAULT_STATE };
        }
        const parsed = JSON.parse(raw);
        return {
            open: Boolean(parsed.open),
            docked: Boolean(parsed.docked),
            width: Number.isFinite(parsed.width) ? parsed.width : DEFAULT_STATE.width,
            height: Number.isFinite(parsed.height) ? parsed.height : DEFAULT_STATE.height,
            composerHeight: Number.isFinite(parsed.composerHeight)
                ? parsed.composerHeight
                : DEFAULT_STATE.composerHeight,
        };
    } catch (e) {
        console.warn('[ChEddi] could not read the persisted drawer state, using defaults.', e);
        return { ...DEFAULT_STATE };
    }
}

export function saveState(state) {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {
        console.warn('[ChEddi] could not persist the drawer state.', e);
    }
}

export function clampSize(width, height) {
    const maxWidth = Math.min(SIZE_LIMITS.maxWidthPx, window.innerWidth * SIZE_LIMITS.maxWidthVw);
    const maxHeight = Math.min(SIZE_LIMITS.maxHeightPx, window.innerHeight * SIZE_LIMITS.maxHeightVh);
    return {
        width: Math.min(Math.max(width, SIZE_LIMITS.minWidth), maxWidth),
        height: Math.min(Math.max(height, SIZE_LIMITS.minHeight), maxHeight),
    };
}

export function clampComposerHeight(height, drawerHeight) {
    const maxHeight = Math.max(
        COMPOSER_LIMITS.minHeight,
        Math.min(
            COMPOSER_LIMITS.maxHeightPx,
            (drawerHeight || DEFAULT_STATE.height) * COMPOSER_LIMITS.maxHeightRatio,
        ),
    );
    return Math.round(Math.min(Math.max(height, COMPOSER_LIMITS.minHeight), maxHeight));
}

export function loadSessionUuid() {
    try {
        return window.localStorage.getItem(SESSION_STORAGE_KEY) || null;
    } catch (e) {
        console.warn('[ChEddi] could not read the stored session id.', e);
        return null;
    }
}

export function saveSessionUuid(uuid) {
    try {
        if (uuid) {
            window.localStorage.setItem(SESSION_STORAGE_KEY, uuid);
        } else {
            window.localStorage.removeItem(SESSION_STORAGE_KEY);
        }
    } catch (e) {
        console.warn('[ChEddi] could not persist the session id.', e);
    }
}

export function loadSessionModel() {
    try {
        return window.localStorage.getItem(SESSION_MODEL_STORAGE_KEY) || null;
    } catch (e) {
        console.warn('[ChEddi] could not read the stored session model.', e);
        return null;
    }
}

export function saveSessionModel(model) {
    try {
        if (model) {
            window.localStorage.setItem(SESSION_MODEL_STORAGE_KEY, model);
        } else {
            window.localStorage.removeItem(SESSION_MODEL_STORAGE_KEY);
        }
    } catch (e) {
        console.warn('[ChEddi] could not persist the session model.', e);
    }
}

export function dockWidth(width, viewportWidth) {
    const available = viewportWidth - MIN_CONTENT_WIDTH;

    return available >= SIZE_LIMITS.minWidth ? Math.min(width, available) : null;
}
