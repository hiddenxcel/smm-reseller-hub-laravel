/**
 * Help-desk badges, worded from the console's side of the desk.
 *
 * Deliberately not shared with the reseller-facing Help/bits: the same status
 * means opposite things depending on who is reading. `answered` is "waiting on
 * us" to a reseller and "needs a reply" to an admin, and a single component
 * trying to serve both would have to be told which side it was on anyway.
 */

const STATUS: Record<string, { label: string; tone: string }> = {
    open: {
        label: 'New',
        tone: 'bg-destructive/10 text-destructive',
    },
    answered: {
        label: 'Needs reply',
        tone: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    },
    pending: {
        label: 'Waiting on them',
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

/** How long a reseller has been waiting, in the units that read fastest. */
export function waitingLabel(hours: number | null): string {
    if (hours === null) {
        return '—';
    }

    if (hours < 1) {
        return 'Just in';
    }

    if (hours < 48) {
        return `${hours}h`;
    }

    return `${Math.floor(hours / 24)}d`;
}

export function whenLabel(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
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
