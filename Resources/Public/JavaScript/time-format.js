import { ll } from '@autodudes/cheddi/i18n.js';

function backendLocale() {
    const lang = document.documentElement.lang;
    if (typeof lang === 'string' && lang !== '') {
        try {
            return Intl.DateTimeFormat.supportedLocalesOf([lang]).length > 0 ? lang : undefined;
        } catch (e) {
            return undefined;
        }
    }
    return undefined;
}

function toDate(unixSeconds) {
    if (typeof unixSeconds !== 'number' || !Number.isFinite(unixSeconds) || unixSeconds <= 0) {
        return null;
    }
    return new Date(unixSeconds * 1000);
}

function isSameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function format(date, options) {
    return new Intl.DateTimeFormat(backendLocale(), options).format(date);
}

export function formatMessageTime(unixSeconds) {
    const date = toDate(unixSeconds);
    if (date === null) {
        return '';
    }
    const time = format(date, { hour: '2-digit', minute: '2-digit' });
    if (isSameDay(date, new Date())) {
        return time;
    }
    return `${format(date, { day: '2-digit', month: '2-digit' })}, ${time}`;
}

export function formatFullDateTime(unixSeconds) {
    const date = toDate(unixSeconds);
    return date === null ? '' : format(date, { dateStyle: 'medium', timeStyle: 'short' });
}

export function formatLastUsed(unixSeconds) {
    const date = toDate(unixSeconds);
    if (date === null) {
        return '';
    }
    const time = format(date, { hour: '2-digit', minute: '2-digit' });
    if (isSameDay(date, new Date())) {
        return ll('cheddi.sessions.lastUsedToday', { time });
    }
    return ll('cheddi.sessions.lastUsedAt', {
        date: format(date, { day: '2-digit', month: '2-digit', year: 'numeric' }),
        time,
    });
}

export function toIsoString(unixSeconds) {
    const date = toDate(unixSeconds);
    return date === null ? '' : date.toISOString();
}
