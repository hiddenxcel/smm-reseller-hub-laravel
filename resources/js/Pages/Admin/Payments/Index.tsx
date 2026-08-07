import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    DataTable,
    dateTime,
    Empty,
    FilterTabs,
    money,
    Pagination,
    pushFilters,
    TabsSkeleton,
} from '../bits';
import {
    PageMeta,
    PaymentDetail,
    PaymentFilterState,
    PaymentRow,
    PaymentTotals,
} from '../types';

type Props = {
    payments: { data: PaymentRow[]; meta: PageMeta };
    filters: PaymentFilterState;
    isFiltered: boolean;
    tabCounts?: Record<string, number>;
    totals?: PaymentTotals;
    gateways?: string[];
    canManage: boolean;
};

const ROUTE = 'admin.payments.index';

export default function PaymentsIndex({
    payments,
    filters,
    isFiltered,
    tabCounts,
    totals,
    gateways,
    canManage,
}: Props) {
    const [open, setOpen] = useState<number | null>(null);

    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Payments</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        What resellers have paid the platform. Not their customers'
                        payments — that money is theirs.
                    </p>
                </div>
            }
        >
            <Head title="Payments — Control" />

            <Deferred data="totals" fallback={<TotalsSkeleton />}>
                <TotalsRow totals={totals} />
            </Deferred>

            <div className="mt-4 flex flex-wrap items-center gap-2">
                <SearchBox filters={filters} />

                <Deferred data="gateways" fallback={<span />}>
                    <select
                        value={filters.gateway ?? ''}
                        onChange={(e) =>
                            pushFilters(ROUTE, {
                                ...filters,
                                gateway: e.target.value || null,
                            })
                        }
                        className="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Any gateway</option>
                        {(gateways ?? []).map((gateway) => (
                            <option key={gateway} value={gateway}>
                                {gateway}
                            </option>
                        ))}
                    </select>
                </Deferred>

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
                    current={filters.status}
                    onSelect={(status) => pushFilters(ROUTE, { ...filters, status })}
                    tabs={[
                        { key: null, label: 'All', count: tabCounts?.all },
                        { key: 'success', label: 'Succeeded', count: tabCounts?.success },
                        { key: 'pending', label: 'Pending', count: tabCounts?.pending },
                        { key: 'failed', label: 'Failed', count: tabCounts?.failed },
                    ]}
                />
            </Deferred>

            <DataTable
                headers={[
                    { label: 'Reseller' },
                    { label: 'Gateway' },
                    { label: 'Reference' },
                    { label: 'Amount', align: 'right' },
                    { label: 'Status' },
                    { label: 'When' },
                ]}
            >
                {payments.data.map((row) => (
                    <tr
                        key={row.id}
                        onClick={() => setOpen(row.id)}
                        className="cursor-pointer border-b border-border last:border-0 hover:bg-accent/50"
                    >
                        <td className="px-4 py-3">
                            <Link
                                href={route('admin.tenants.show', row.tenantId)}
                                onClick={(e) => e.stopPropagation()}
                                className="truncate font-medium hover:underline"
                            >
                                {row.tenant}
                            </Link>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">{row.gateway}</td>
                        <td className="px-4 py-3">
                            <span className="block max-w-[12rem] truncate font-mono text-xs text-muted-foreground">
                                {row.reference ?? '—'}
                            </span>
                        </td>
                        <td className="px-4 py-3 text-right tabular-nums">
                            {money(row.amount)}
                            {row.creditApplied > 0 && (
                                <span className="block text-xs text-muted-foreground">
                                    +{money(row.creditApplied)} credit
                                </span>
                            )}
                        </td>
                        <td className="px-4 py-3">
                            <StatusChip status={row.status} />
                            {row.stale && (
                                <span
                                    className="mt-1 flex items-center gap-1 text-[10px] text-amber-700 dark:text-amber-300"
                                    title="Taken but never confirmed"
                                >
                                    <AlertTriangle className="size-3" />
                                    Stuck
                                </span>
                            )}
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">
                            {dateTime(row.at)}
                        </td>
                    </tr>
                ))}
            </DataTable>

            {payments.data.length === 0 && (
                <Empty>
                    {isFiltered
                        ? 'No payments match these filters.'
                        : 'No payments yet.'}
                </Empty>
            )}

            <Pagination
                meta={payments.meta}
                onPage={(page) => pushFilters(ROUTE, filters, page)}
            />

            {open !== null && (
                <PaymentPanel
                    id={open}
                    canManage={canManage}
                    onClose={() => setOpen(null)}
                />
            )}
        </AdminLayout>
    );
}

function TotalsRow({ totals }: { totals?: PaymentTotals }) {
    if (!totals) {
        return null;
    }

    const tiles = [
        { label: 'Collected', value: money(totals.collected) },
        { label: 'Credit applied', value: money(totals.creditApplied) },
        { label: 'Pending value', value: money(totals.pendingValue) },
    ];

    return (
        <div className="grid gap-3 sm:grid-cols-3">
            {tiles.map((tile) => (
                <div
                    key={tile.label}
                    className="rounded-xl border border-border bg-card p-4"
                >
                    <p className="text-xs text-muted-foreground">{tile.label}</p>
                    <p className="font-heading mt-1 text-xl font-extrabold tabular-nums">
                        {tile.value}
                    </p>
                </div>
            ))}
        </div>
    );
}

function TotalsSkeleton() {
    return (
        <div className="grid gap-3 sm:grid-cols-3">
            {[0, 1, 2].map((i) => (
                <div key={i} className="h-[74px] animate-pulse rounded-xl bg-muted" />
            ))}
        </div>
    );
}

function SearchBox({ filters }: { filters: PaymentFilterState }) {
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
                placeholder="Search reference, reseller or email"
                className="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            />
        </div>
    );
}

function StatusChip({ status }: { status: string }) {
    const styles: Record<string, string> = {
        success:
            'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]',
        pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        failed: 'bg-destructive/10 text-destructive',
    };

    return (
        <span
            className={`inline-flex rounded px-1.5 py-0.5 text-xs font-medium capitalize ${
                styles[status] ?? 'bg-muted text-muted-foreground'
            }`}
        >
            {status}
        </span>
    );
}

/**
 * One payment, fetched when opened.
 *
 * The raw gateway response is the whole reason for looking at a single payment,
 * and it is far too large to carry on every row of the list.
 */
function PaymentPanel({
    id,
    canManage,
    onClose,
}: {
    id: number;
    canManage: boolean;
    onClose: () => void;
}) {
    const [payment, setPayment] = useState<PaymentDetail | null>(null);
    const [acting, setActing] = useState<'confirm' | 'fail' | 'reapply' | null>(null);

    useEffect(() => {
        let cancelled = false;

        window.axios.get(route('admin.payments.show', id)).then((response) => {
            if (!cancelled) {
                setPayment(response.data.payment);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [id]);

    return (
        <div
            className="fixed inset-0 z-50 flex justify-end bg-foreground/40"
            onClick={onClose}
        >
            <div
                className="scroll-slim h-full w-full max-w-lg overflow-y-auto border-l border-border bg-card p-6"
                onClick={(e) => e.stopPropagation()}
            >
                {payment === null ? (
                    <div className="space-y-3">
                        {[0, 1, 2, 3, 4].map((i) => (
                            <div key={i} className="h-6 animate-pulse rounded bg-muted" />
                        ))}
                    </div>
                ) : (
                    <>
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <h2 className="font-heading text-lg font-extrabold">
                                    {money(payment.amount)}{' '}
                                    <span className="text-sm font-normal text-muted-foreground">
                                        {payment.currency}
                                    </span>
                                </h2>
                                <p className="truncate text-sm text-muted-foreground">
                                    {payment.tenant} · {payment.gateway}
                                </p>
                            </div>

                            <button
                                type="button"
                                onClick={onClose}
                                className="rounded-lg p-1 text-muted-foreground hover:bg-accent"
                                aria-label="Close"
                            >
                                <X className="size-5" />
                            </button>
                        </div>

                        <dl className="mt-5 divide-y divide-border rounded-lg border border-border">
                            <Row label="Status">
                                <StatusChip status={payment.status} />
                            </Row>
                            <Row label="Reference">
                                <span className="break-all font-mono text-xs">
                                    {payment.reference ?? '—'}
                                </span>
                            </Row>
                            {payment.binanceOrderId && (
                                <Row label="Binance order">
                                    <span className="break-all font-mono text-xs">
                                        {payment.binanceOrderId}
                                    </span>
                                </Row>
                            )}
                            <Row label="Credit applied">
                                {money(payment.creditApplied)}
                            </Row>
                            <Row label="Term">
                                {payment.months ? `${payment.months} month(s)` : '—'}
                            </Row>
                            <Row label="Taken">{dateTime(payment.at)}</Row>
                        </dl>

                        {Array.isArray(payment.items) && payment.items.length > 0 && (
                            <section className="mt-5">
                                <h3 className="font-heading mb-2 text-sm font-bold">
                                    What it bought
                                </h3>
                                <ul className="space-y-1 text-sm">
                                    {(payment.items as Array<Record<string, unknown>>).map(
                                        (item, index) => (
                                            <li
                                                key={index}
                                                className="rounded-lg border border-border px-3 py-2 text-muted-foreground"
                                            >
                                                <span className="font-medium text-foreground">
                                                    {String(item.type ?? 'line')}
                                                </span>{' '}
                                                {String(item.key ?? '')}
                                                {item.months
                                                    ? ` · ${String(item.months)} month(s)`
                                                    : ''}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </section>
                        )}

                        {payment.rawResponse && (
                            <section className="mt-5">
                                <h3 className="font-heading mb-2 text-sm font-bold">
                                    Gateway response
                                </h3>
                                <pre className="scroll-slim max-h-64 overflow-auto rounded-lg border border-border bg-muted/40 p-3 text-[11px] leading-relaxed">
                                    {payment.rawResponse}
                                </pre>
                            </section>
                        )}

                        {canManage && (
                            <section className="mt-6 border-t border-border pt-4">
                                <h3 className="font-heading mb-1 text-sm font-bold">
                                    Settle by hand
                                </h3>
                                <p className="mb-3 text-xs text-muted-foreground">
                                    Confirming grants the subscription without a gateway
                                    signature, so the reason is required and recorded.
                                </p>

                                <div className="flex flex-wrap gap-2">
                                    {payment.status === 'pending' && (
                                        <>
                                            <ActBtn
                                                label="Confirm & apply"
                                                onClick={() => setActing('confirm')}
                                            />
                                            <ActBtn
                                                label="Mark failed"
                                                destructive
                                                onClick={() => setActing('fail')}
                                            />
                                        </>
                                    )}
                                    {payment.status === 'success' && (
                                        <ActBtn
                                            label="Re-apply purchase"
                                            onClick={() => setActing('reapply')}
                                        />
                                    )}
                                    {payment.status === 'failed' && (
                                        <p className="text-xs text-muted-foreground">
                                            Marked failed. Nothing further to do here.
                                        </p>
                                    )}
                                </div>
                            </section>
                        )}
                    </>
                )}

                {acting && payment && (
                    <ReasonDialog
                        payment={payment}
                        action={acting}
                        onClose={() => setActing(null)}
                        onDone={onClose}
                    />
                )}
            </div>
        </div>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4 px-3 py-2.5 text-sm">
            <dt className="shrink-0 text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-right font-medium">{children}</dd>
        </div>
    );
}

function ActBtn({
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
                'rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors',
                destructive
                    ? 'border-destructive/30 text-destructive hover:bg-destructive/10'
                    : 'border-border hover:bg-accent',
            ].join(' ')}
        >
            {label}
        </button>
    );
}

function ReasonDialog({
    payment,
    action,
    onClose,
    onDone,
}: {
    payment: PaymentDetail;
    action: 'confirm' | 'fail' | 'reapply';
    onClose: () => void;
    onDone: () => void;
}) {
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const copy = {
        confirm: {
            title: 'Confirm this payment',
            blurb: `${money(payment.amount)} from ${payment.tenant} will be treated as paid, and the subscription it bought will be switched on.`,
            cta: 'Confirm & apply',
        },
        fail: {
            title: 'Mark as failed',
            blurb: 'Records that this payment never completed. No money moves, and nothing already granted is taken away.',
            cta: 'Mark failed',
        },
        reapply: {
            title: 'Re-apply this purchase',
            blurb: 'For a payment that succeeded but whose activation did not finish. Safe to run twice — activation is idempotent.',
            cta: 'Re-apply',
        },
    }[action];

    const submit = () => {
        setSubmitting(true);

        router.post(
            route('admin.payments.act', [payment.id, action]),
            { reason },
            {
                onSuccess: () => {
                    onClose();
                    onDone();
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-foreground/50 p-4">
            <div className="w-full max-w-md rounded-xl border border-border bg-card p-6">
                <h2 className="font-heading text-lg font-extrabold">{copy.title}</h2>
                <p className="mt-2 text-sm text-muted-foreground">{copy.blurb}</p>

                <label className="mt-4 block text-xs font-medium text-muted-foreground">
                    Reason
                </label>
                <input
                    type="text"
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    placeholder="e.g. confirmed in Cryptomus dashboard, tx 0x9f…"
                    maxLength={255}
                    autoFocus
                    className="mt-1 w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm"
                />

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
                            action === 'fail'
                                ? 'bg-destructive text-white'
                                : 'bg-primary text-primary-foreground',
                        ].join(' ')}
                    >
                        {submitting ? 'Working…' : copy.cta}
                    </button>
                </div>
            </div>
        </div>
    );
}
