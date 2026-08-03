/**
 * Time and name formatting for the inbox.
 *
 * A conversation list is scanned, not read — "3h" tells you what you need in
 * a glance where a full timestamp would not.
 */

export function relativeTime(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    const then = new Date(iso).getTime();

    if (Number.isNaN(then)) {
        return '';
    }

    const seconds = Math.floor((Date.now() - then) / 1000);

    if (seconds < 60) {
        return 'now';
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h`;
    }

    if (seconds < 172800) {
        return 'yesterday';
    }

    if (seconds < 604800) {
        return `${Math.floor(seconds / 86400)}d`;
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

/** The divider between days inside a thread. */
export function dayLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString();

    if (sameDay(date, today)) {
        return 'Today';
    }

    if (sameDay(date, yesterday)) {
        return 'Yesterday';
    }

    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric',
    });
}

export function clockTime(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    return new Date(iso).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Two characters for the avatar. A phone number has no initials worth having,
 * so it falls back to its last two digits — which is what a reseller
 * recognises a number by anyway.
 */
export function initials(label: string): string {
    const trimmed = label.trim();

    if (trimmed === '') {
        return '?';
    }

    const digitsOnly = trimmed.replace(/[\s+]/g, '');

    if (/^\d+$/.test(digitsOnly)) {
        return digitsOnly.slice(-2);
    }

    const parts = trimmed.split(/\s+/);

    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }

    return trimmed.slice(0, 2).toUpperCase();
}
