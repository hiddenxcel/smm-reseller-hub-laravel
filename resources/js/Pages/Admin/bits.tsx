import { router } from '@inertiajs/react';
import { PageMeta, ServiceState } from './types';

/**
 * The small repeated pieces the admin screens share: status chips, service
 * chips, and the money/date formatting used by both the list and the detail
 * page. Kept together so a reseller's status reads identically in both.
 */

const SERVICE_LABELS: Record<string, string> = {
    order_bot: 'Order',
    support_bot: 'Support',
    ai_tickets: 'AI Tickets',
    ai_chat: 'AI Chat',
    number_rental: 'Number',
};

export function StatusChip({ status }: { status: string }) {
    const isActive = status === 'active';

    return (
        <span
            className={[
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium',
                isActive
                    ? 'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]'
                    : 'bg-destructive/10 text-destructive',
            ].join(' ')}
        >
            <span
                className={[
                    'size-1.5 rounded-full',
                    isActive ? 'bg-[#006300] dark:bg-[#0ca30c]' : 'bg-destructive',
                ].join(' ')}
                aria-hidden
            />
            {isActive ? 'Active' : 'Suspended'}
        </span>
    );
}

/**
 * Which services a reseller is running.
 *
 * Only what they hold is drawn. Rendering all five with the unheld ones greyed
 * would make every row the same width and the same shape, which is exactly what
 * a scannable list must not be.
 */
export function ServiceChips({
    services,
}: {
    services: Record<string, ServiceState>;
}) {
    const held = Object.entries(services).filter(
        ([, state]) => state === 'active' || state === 'sandbox',
    );

    if (held.length === 0) {
        return <span className="text-xs text-muted-foreground">None</span>;
    }

    return (
        <span className="flex flex-wrap gap-1">
            {held.map(([key, state]) => (
                <span
                    key={key}
                    title={state === 'sandbox' ? 'Sandbox — not paid' : 'Active'}
                    className={[
                        'inline-flex rounded px-1.5 py-0.5 text-[10px] font-medium',
                        state === 'active'
                            ? 'bg-primary/10 text-primary'
                            : 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
                    ].join(' ')}
                >
                    {SERVICE_LABELS[key] ?? key}
                    {state === 'sandbox' && ' ·'}
                </span>
            ))}
        </span>
    );
}

export function money(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return `$${value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

/** A date a human reads at a glance — the year only when it is not this one. */
export function shortDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);
    const now = new Date();

    return date.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year:
            date.getFullYear() === now.getFullYear() ? undefined : 'numeric',
    });
}

export function dateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function Empty({ children }: { children: React.ReactNode }) {
    return (
        <p className="py-10 text-center text-sm text-muted-foreground">{children}</p>
    );
}

/**
 * Push list state into the URL rather than holding it in React.
 *
 * Shared by every admin table for the same reason the reseller-facing ones do
 * it: a filtered view stays a link an admin can paste into a ticket. Nulls drop
 * out so those links stay short.
 */
export function pushFilters(
    routeName: string,
    filters: Record<string, unknown>,
    page?: number,
): void {
    const params: Record<string, string> = {};

    Object.entries(filters).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            params[key] = String(value);
        }
    });

    if (page && page > 1) {
        params.page = String(page);
    }

    router.get(route(routeName), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

/** Counted tabs across the top of a list. */
export function FilterTabs({
    tabs,
    current,
    onSelect,
}: {
    tabs: Array<{ key: string | null; label: string; count?: number }>;
    current: string | null;
    onSelect: (key: string | null) => void;
}) {
    return (
        <div className="scroll-slim mt-4 flex gap-1 overflow-x-auto border-b border-border">
            {tabs.map((tab) => {
                const isCurrent = current === tab.key;

                return (
                    <button
                        key={tab.label}
                        type="button"
                        onClick={() => onSelect(tab.key)}
                        className={[
                            '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm transition-colors',
                            isCurrent
                                ? 'border-primary font-semibold text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        ].join(' ')}
                        aria-current={isCurrent ? 'page' : undefined}
                    >
                        {tab.label}
                        {tab.count !== undefined && (
                            <span className="ml-1.5 tabular-nums text-muted-foreground">
                                {tab.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

export function Pagination({
    meta,
    onPage,
}: {
    meta: PageMeta;
    onPage: (page: number) => void;
}) {
    if (meta.lastPage <= 1) {
        return null;
    }

    return (
        <div className="mt-4 flex items-center justify-between gap-3 text-sm">
            <p className="text-muted-foreground">
                {meta.from ?? 0}–{meta.to ?? 0} of {meta.total.toLocaleString()}
            </p>

            <div className="flex gap-2">
                <button
                    type="button"
                    disabled={meta.currentPage <= 1}
                    onClick={() => onPage(meta.currentPage - 1)}
                    className="rounded-lg border border-border px-3 py-1.5 transition-colors hover:bg-accent disabled:opacity-40"
                >
                    Previous
                </button>
                <button
                    type="button"
                    disabled={meta.currentPage >= meta.lastPage}
                    onClick={() => onPage(meta.currentPage + 1)}
                    className="rounded-lg border border-border px-3 py-1.5 transition-colors hover:bg-accent disabled:opacity-40"
                >
                    Next
                </button>
            </div>
        </div>
    );
}

/** The shared table shell: horizontal scroll owned by the wrapper, not the page. */
export function DataTable({
    headers,
    children,
    minWidth = '48rem',
}: {
    headers: Array<{ label: string; align?: 'right' }>;
    children: React.ReactNode;
    minWidth?: string;
}) {
    return (
        <div className="mt-4 overflow-x-auto rounded-xl border border-border bg-card">
            <table className="w-full text-sm" style={{ minWidth }}>
                <thead>
                    <tr className="border-b border-border text-left text-xs uppercase tracking-wider text-muted-foreground">
                        {headers.map((header) => (
                            <th
                                key={header.label}
                                className={[
                                    'px-4 py-3 font-semibold',
                                    header.align === 'right' ? 'text-right' : '',
                                ].join(' ')}
                            >
                                {header.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

export function TabsSkeleton() {
    return (
        <div className="mt-4 flex gap-3 border-b border-border pb-2">
            {[0, 1, 2, 3].map((index) => (
                <div key={index} className="h-5 w-20 animate-pulse rounded bg-muted" />
            ))}
        </div>
    );
}
