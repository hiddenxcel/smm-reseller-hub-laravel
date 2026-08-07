import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    DataTable,
    Empty,
    FilterTabs,
    Pagination,
    pushFilters,
    shortDate,
    TabsSkeleton,
} from '../bits';
import {
    PageMeta,
    SubscriptionFilterState,
    SubscriptionRow,
    SubscriptionState,
} from '../types';

type Props = {
    subscriptions: { data: SubscriptionRow[]; meta: PageMeta };
    filters: SubscriptionFilterState;
    isFiltered: boolean;
    tabCounts?: Record<string, number>;
    serviceKeys: string[];
    canManage: boolean;
};

const SERVICE_LABELS: Record<string, string> = {
    order_bot: 'Order Bot',
    support_bot: 'Support Bot',
    ai_tickets: 'AI Tickets',
    ai_chat: 'AI Chat',
    number_rental: 'Number Rental',
};

const ROUTE = 'admin.subscriptions.index';

export default function SubscriptionsIndex({
    subscriptions,
    filters,
    isFiltered,
    tabCounts,
    serviceKeys,
    canManage,
}: Props) {
    const [acting, setActing] = useState<{
        row: SubscriptionRow;
        action: 'extend' | 'cancel' | 'reinstate';
    } | null>(null);

    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Subscriptions</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Who is subscribed to what, soonest to lapse first.
                    </p>
                </div>
            }
        >
            <Head title="Subscriptions — Control" />

            <div className="flex flex-wrap items-center gap-2">
                <SearchBox filters={filters} />

                <select
                    value={filters.service ?? ''}
                    onChange={(e) =>
                        pushFilters(ROUTE, { ...filters, service: e.target.value || null })
                    }
                    className="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                >
                    <option value="">Any service</option>
                    {serviceKeys.map((key) => (
                        <option key={key} value={key}>
                            {SERVICE_LABELS[key] ?? key}
                        </option>
                    ))}
                </select>

                {isFiltered && (
                    <button
                        type="button"
                        onClick={() => router.get(route(ROUTE))}
                        className="inline-flex items-center gap-1 rounded-lg border border-border px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent"
                    >
                        <X className="size-3.5" />
                        Clear
                    </button>
                )}
            </div>

            <Deferred data="tabCounts" fallback={<TabsSkeleton />}>
                <FilterTabs
                    current={filters.state}
                    onSelect={(state) => pushFilters(ROUTE, { ...filters, state })}
                    tabs={[
                        { key: null, label: 'All', count: tabCounts?.all },
                        { key: 'active', label: 'Active', count: tabCounts?.active },
                        { key: 'expiring', label: 'Expiring', count: tabCounts?.expiring },
                        { key: 'trial', label: 'Trial', count: tabCounts?.trial },
                        { key: 'expired', label: 'Expired', count: tabCounts?.expired },
                        {
                            key: 'cancelled',
                            label: 'Cancelled',
                            count: tabCounts?.cancelled,
                        },
                    ]}
                />
            </Deferred>

            <DataTable
                headers={[
                    { label: 'Reseller' },
                    { label: 'Service' },
                    { label: 'State' },
                    { label: 'Started' },
                    { label: 'Ends' },
                    { label: 'Actions', align: 'right' },
                ]}
            >
                {subscriptions.data.map((row) => (
                    <tr
                        key={row.id}
                        className="border-b border-border last:border-0 hover:bg-accent/50"
                    >
                        <td className="px-4 py-3">
                            <Link
                                href={route('admin.tenants.show', row.tenantId)}
                                className="truncate font-medium hover:underline"
                            >
                                {row.tenant}
                            </Link>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">
                            {SERVICE_LABELS[row.service] ?? row.service}
                            {row.plan && (
                                <span className="block text-xs">{row.plan}</span>
                            )}
                        </td>
                        <td className="px-4 py-3">
                            <StateChip state={row.state} />
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">
                            {shortDate(row.startsAt)}
                        </td>
                        <td className="px-4 py-3">
                            {row.endsAt ? (
                                <>
                                    <span className="block">{shortDate(row.endsAt)}</span>
                                    <DaysLeft days={row.daysLeft} />
                                </>
                            ) : (
                                <span className="text-muted-foreground">Open</span>
                            )}
                        </td>
                        <td className="px-4 py-3 text-right">
                            {canManage && (
                                <RowActions row={row} onAct={(action) => setActing({ row, action })} />
                            )}
                        </td>
                    </tr>
                ))}
            </DataTable>

            {subscriptions.data.length === 0 && (
                <Empty>
                    {isFiltered
                        ? 'No subscriptions match these filters.'
                        : 'Nobody is subscribed yet.'}
                </Empty>
            )}

            <Pagination
                meta={subscriptions.meta}
                onPage={(page) => pushFilters(ROUTE, filters, page)}
            />

            {acting && (
                <ActionDialog
                    row={acting.row}
                    action={acting.action}
                    onClose={() => setActing(null)}
                />
            )}
        </AdminLayout>
    );
}

function SearchBox({ filters }: { filters: SubscriptionFilterState }) {
    const [search, setSearch] = useState(filters.q ?? '');

    useEffect(() => {
        if (search === (filters.q ?? '')) {
            return;
        }

        const timer = setTimeout(() => {
            pushFilters(ROUTE, { ...filters, q: search || null });
        }, 300);

        return () => clearTimeout(timer);
    }, [search]);

    return (
        <div className="relative min-w-[16rem] flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search reseller name or email"
                className="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            />
        </div>
    );
}

function StateChip({ state }: { state: SubscriptionState }) {
    const styles: Record<SubscriptionState, string> = {
        active: 'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]',
        expiring: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        trial: 'bg-primary/10 text-primary',
        expired: 'bg-destructive/10 text-destructive',
        cancelled: 'bg-muted text-muted-foreground',
        pending: 'bg-muted text-muted-foreground',
    };

    return (
        <span
            className={`inline-flex rounded px-1.5 py-0.5 text-xs font-medium capitalize ${styles[state]}`}
        >
            {state}
        </span>
    );
}

/** Context the date alone does not give: how urgent this row is. */
function DaysLeft({ days }: { days: number | null }) {
    if (days === null) {
        return null;
    }

    if (days < 0) {
        return (
            <span className="text-xs text-destructive">
                {Math.abs(days)}d ago
            </span>
        );
    }

    return (
        <span
            className={[
                'text-xs',
                days <= 7 ? 'text-amber-700 dark:text-amber-300' : 'text-muted-foreground',
            ].join(' ')}
        >
            in {days}d
        </span>
    );
}

function RowActions({
    row,
    onAct,
}: {
    row: SubscriptionRow;
    onAct: (action: 'extend' | 'cancel' | 'reinstate') => void;
}) {
    const dead = row.state === 'cancelled' || row.state === 'expired';

    return (
        <div className="flex justify-end gap-1.5">
            {dead ? (
                <ActionLink label="Reinstate" onClick={() => onAct('reinstate')} />
            ) : (
                <>
                    <ActionLink label="Extend" onClick={() => onAct('extend')} />
                    <ActionLink
                        label="Cancel"
                        destructive
                        onClick={() => onAct('cancel')}
                    />
                </>
            )}
        </div>
    );
}

function ActionLink({
    label,
    onClick,
    destructive = false,
}: {
    label: string;
    onClick: () => void;
    destructive?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={[
                'rounded-lg border px-2 py-1 text-xs font-medium transition-colors',
                destructive
                    ? 'border-destructive/30 text-destructive hover:bg-destructive/10'
                    : 'border-border hover:bg-accent',
            ].join(' ')}
        >
            {label}
        </button>
    );
}

/**
 * Every action here grants or removes access somebody paid for, so each asks
 * for a reason before it will submit. The server requires it too.
 */
function ActionDialog({
    row,
    action,
    onClose,
}: {
    row: SubscriptionRow;
    action: 'extend' | 'cancel' | 'reinstate';
    onClose: () => void;
}) {
    const [months, setMonths] = useState('1');
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const needsMonths = action !== 'cancel';

    const titles = {
        extend: `Extend ${row.tenant}`,
        cancel: `Cancel ${row.tenant}`,
        reinstate: `Reinstate ${row.tenant}`,
    };

    const blurbs = {
        extend: 'Time is added to what is left, so nothing already paid for is lost.',
        cancel: 'Access ends immediately. No money is refunded by this.',
        reinstate: 'Access is restored, counting from today.',
    };

    const submit = () => {
        setSubmitting(true);

        router.post(
            route('admin.subscriptions.act', [row.id, action]),
            needsMonths ? { months, reason } : { reason },
            { onSuccess: onClose, onFinish: () => setSubmitting(false) },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4">
            <div className="w-full max-w-md rounded-xl border border-border bg-card p-6">
                <h2 className="font-heading text-lg font-extrabold">{titles[action]}</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {SERVICE_LABELS[row.service] ?? row.service} · {blurbs[action]}
                </p>

                {needsMonths && (
                    <div className="mt-4">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">
                            Months
                        </label>
                        <input
                            type="number"
                            min={1}
                            max={36}
                            value={months}
                            onChange={(e) => setMonths(e.target.value)}
                            className="w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm"
                        />
                    </div>
                )}

                <div className="mt-3">
                    <label className="mb-1 block text-xs font-medium text-muted-foreground">
                        Reason
                    </label>
                    <input
                        type="text"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="e.g. paid by bank transfer, ref 8841"
                        maxLength={255}
                        autoFocus
                        className="w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm"
                    />
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-border px-3 py-2 text-sm transition-colors hover:bg-accent"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={submitting || reason.trim() === ''}
                        className={[
                            'rounded-lg px-3 py-2 text-sm font-semibold transition-opacity hover:opacity-90 disabled:opacity-50',
                            action === 'cancel'
                                ? 'bg-destructive text-white'
                                : 'bg-primary text-primary-foreground',
                        ].join(' ')}
                    >
                        {submitting ? 'Working…' : 'Confirm'}
                    </button>
                </div>
            </div>
        </div>
    );
}
