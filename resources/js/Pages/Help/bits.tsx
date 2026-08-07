/**
 * The small pieces the Help screens share.
 *
 * A ticket's status is the one thing shown on both the list and the thread, so
 * it is defined once — a badge that said "Answered" in one place and "Waiting"
 * in the other would be read as two different states.
 */

/**
 * Status as the reseller should understand it.
 *
 * The wording is deliberately from their side of the conversation: the database
 * calls it `answered` when the reseller has written and we have not replied,
 * which from their chair is "waiting on us", not "answered".
 */
const STATUS: Record<string, { label: string; tone: string }> = {
    open: {
        label: 'Waiting on us',
        tone: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    },
    answered: {
        label: 'Waiting on us',
        tone: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    },
    pending: {
        label: 'Replied',
        tone: 'bg-primary/10 text-primary',
    },
    resolved: {
        label: 'Resolved',
        tone: 'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]',
    },
    closed: {
        label: 'Closed',
        tone: 'bg-muted text-muted-foreground',
    },
};

export function StatusBadge({ status }: { status: string }) {
    const state = STATUS[status] ?? {
        label: status,
        tone: 'bg-muted text-muted-foreground',
    };

    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${state.tone}`}
        >
            {state.label}
        </span>
    );
}

/**
 * Only the priorities worth drawing attention to get colour.
 *
 * Low and normal render as plain text: if every row carries a coloured chip,
 * the critical one stops standing out, which is the only job this has.
 */
export function PriorityBadge({ priority }: { priority: string }) {
    if (priority === 'low' || priority === 'normal') {
        return <span className="text-xs capitalize text-muted-foreground">{priority}</span>;
    }

    return (
        <span
            className={[
                'inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                priority === 'critical'
                    ? 'bg-destructive/10 text-destructive'
                    : 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
            ].join(' ')}
        >
            {priority}
        </span>
    );
}

/** Relative for the recent past, absolute once that stops being useful. */
export function whenLabel(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);
    const minutes = Math.round((Date.now() - date.getTime()) / 60000);

    if (minutes < 1) {
        return 'Just now';
    }

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    if (minutes < 60 * 24) {
        return `${Math.floor(minutes / 60)}h ago`;
    }

    if (minutes < 60 * 24 * 7) {
        return `${Math.floor(minutes / (60 * 24))}d ago`;
    }

    return date.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: date.getFullYear() === new Date().getFullYear() ? undefined : 'numeric',
    });
}

export function messageTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
