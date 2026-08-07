import { formatMoney } from '@/components/charts/chart-tokens';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    Inbox,
    Loader2,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import BulkBar from './BulkBar';
import FilterBar from './FilterBar';
import OrderDetails from './OrderDetails';
import StatusBadge, { PaymentBadge } from './StatusBadge';
import {
    OrderAction,
    OrderRow,
    OrderStatusGroup,
    OrdersPageProps,
    SortKey,
} from './types';
import { useOrderFilters } from './useOrderFilters';

const COLUMNS: Array<{
    key: SortKey | null;
    label: string;
    align?: 'right';
    className?: string;
}> = [
    { key: null, label: 'Order', className: 'w-[8.5rem]' },
    { key: 'customer', label: 'Customer', className: 'w-[10rem]' },
    { key: 'service', label: 'Service' },
    { key: 'quantity', label: 'Qty', align: 'right', className: 'w-[6rem]' },
    { key: 'amount', label: 'Amount', align: 'right', className: 'w-[7rem]' },
    { key: null, label: 'Payment', className: 'w-[7rem]' },
    { key: 'status', label: 'Status', className: 'w-[9rem]' },
    { key: 'created_at', label: 'Date', align: 'right', className: 'w-[9rem]' },
];

export default function Orders({
    orders,
    filters,
    isFiltered,
    tabCounts,
    summary,
    panels,
    pageSizes,
    statusLabels,
    bulkLimits,
}: OrdersPageProps) {
    const { apply, sortBy, reset, busy } = useOrderFilters(filters);

    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [detailId, setDetailId] = useState<number | null>(null);
    const [acting, setActing] = useState(false);
    const [selectedBeyondPage, setSelectedBeyondPage] = useState(false);

    const rows = orders.data;
    const meta = orders.meta;

    // A selection is only meaningful against the rows it was made on. Changing
    // the filter or the page can drop matching rows out of view, and acting on
    // ids the reseller can no longer see is exactly the kind of surprise a
    // bulk action must not spring.
    useEffect(() => {
        setSelected(new Set());
        setSelectedBeyondPage(false);
    }, [filters, meta.currentPage]);

    // The detail panel reads from the current rows, so an action that changes
    // a row is reflected without holding a second copy of it in state.
    const detailOrder = useMemo(
        () => rows.find((row) => row.id === detailId) ?? null,
        [rows, detailId],
    );

    const pageIds = useMemo(() => rows.map((row) => row.id), [rows]);
    const allOnPageSelected =
        pageIds.length > 0 && pageIds.every((id) => selected.has(id));

    const toggleRow = useCallback((id: number) => {
        setSelected((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });
    }, []);

    const toggleAllOnPage = useCallback(() => {
        setSelectedBeyondPage(false);
        setSelected((current) => {
            if (pageIds.every((id) => current.has(id))) {
                return new Set();
            }

            return new Set(pageIds);
        });
    }, [pageIds]);

    /** Pull every id the filter matches, for "select all N matching". */
    const selectAllMatching = useCallback(async () => {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');

        const response = await fetch(
            `${route('orders.matching-ids')}?${params.toString()}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            return;
        }

        const body: { ids: number[] } = await response.json();

        setSelected(new Set(body.ids));
        setSelectedBeyondPage(true);
    }, []);

    const runAction = useCallback(
        (order: OrderRow, action: OrderAction, status?: OrderStatusGroup) => {
            setActing(true);

            router.post(
                route('orders.act', order.id),
                { action, status },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => setActing(false),
                },
            );
        },
        [],
    );

    const runBulk = useCallback(
        (action: OrderAction, status?: OrderStatusGroup) => {
            setActing(true);

            router.post(
                route('orders.bulk'),
                { action, status, ids: Array.from(selected) },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        setSelected(new Set());
                        setSelectedBeyondPage(false);
                    },
                    onFinish: () => setActing(false),
                },
            );
        },
        [selected],
    );

    return (
        <AuthenticatedLayout bleed>
            <Head title="Orders" />

            {/* Sticky through the whole scroll: with a hundred rows on screen,
                the column headers and the status tabs are what keep a reseller
                oriented, and losing them is what makes a long table feel lost. */}
            <div className="sticky top-0 z-30 border-b border-border bg-background/95 px-4 pb-3 pt-5 backdrop-blur sm:px-8">
                <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-2xl font-extrabold tracking-tight">
                            Orders
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {summary ? (
                                <>
                                    {summary.orders.toLocaleString('en-US')}{' '}
                                    {summary.orders === 1 ? 'order' : 'orders'} ·{' '}
                                    {formatMoney(summary.revenue)} taken
                                </>
                            ) : (
                                <span className="opacity-0">Loading totals</span>
                            )}
                        </p>
                    </div>

                    {busy && (
                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Loader2 className="size-3.5 animate-spin" />
                            Updating
                        </span>
                    )}
                </div>

                <FilterBar
                    filters={filters}
                    tabCounts={tabCounts}
                    statusLabels={statusLabels}
                    panels={panels}
                    isFiltered={isFiltered}
                    onApply={apply}
                    onReset={reset}
                />
            </div>

            <div className="px-4 pb-24 pt-4 sm:px-8">
                {rows.length === 0 ? (
                    <EmptyState isFiltered={isFiltered} onReset={reset} />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full min-w-[56rem] border-collapse text-sm">
                            <thead>
                                <tr className="border-b border-border bg-muted/40 text-left">
                                    <th scope="col" className="w-10 px-3 py-2.5">
                                        <input
                                            type="checkbox"
                                            checked={allOnPageSelected}
                                            onChange={toggleAllOnPage}
                                            aria-label="Select every order on this page"
                                            className="size-4 cursor-pointer rounded border-border accent-primary"
                                        />
                                    </th>

                                    {COLUMNS.map((column) => (
                                        <th
                                            key={column.label}
                                            scope="col"
                                            className={[
                                                'px-3 py-2.5 text-xs font-semibold text-muted-foreground',
                                                column.align === 'right'
                                                    ? 'text-right'
                                                    : '',
                                                column.className ?? '',
                                            ].join(' ')}
                                            aria-sort={
                                                column.key && filters.sort === column.key
                                                    ? filters.dir === 'asc'
                                                        ? 'ascending'
                                                        : 'descending'
                                                    : undefined
                                            }
                                        >
                                            {column.key === null ? (
                                                column.label
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => sortBy(column.key!)}
                                                    className={[
                                                        'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                                                        column.align === 'right'
                                                            ? 'flex-row-reverse'
                                                            : '',
                                                    ].join(' ')}
                                                >
                                                    {column.label}
                                                    <SortIcon
                                                        active={filters.sort === column.key}
                                                        dir={filters.dir}
                                                    />
                                                </button>
                                            )}
                                        </th>
                                    ))}
                                </tr>
                            </thead>

                            <tbody>
                                {rows.map((order) => (
                                    <Row
                                        key={order.id}
                                        order={order}
                                        selected={selected.has(order.id)}
                                        statusLabels={statusLabels}
                                        onToggle={toggleRow}
                                        onOpen={setDetailId}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {rows.length > 0 && (
                    <Pagination
                        meta={meta}
                        pageSizes={pageSizes}
                        onPage={(page) => apply({ page })}
                        onPerPage={(perPage) => apply({ perPage, page: 1 })}
                    />
                )}
            </div>

            <BulkBar
                count={selected.size}
                totalMatching={meta.total}
                allSelected={selectedBeyondPage}
                limits={bulkLimits}
                statusLabels={statusLabels}
                pending={acting}
                onAction={runBulk}
                onSelectAllMatching={selectAllMatching}
                onClear={() => {
                    setSelected(new Set());
                    setSelectedBeyondPage(false);
                }}
            />

            <OrderDetails
                order={detailOrder}
                statusLabels={statusLabels}
                pending={acting}
                onClose={() => setDetailId(null)}
                onAction={runAction}
            />
        </AuthenticatedLayout>
    );
}

function Row({
    order,
    selected,
    statusLabels,
    onToggle,
    onOpen,
}: {
    order: OrderRow;
    selected: boolean;
    statusLabels: Record<OrderStatusGroup, string>;
    onToggle: (id: number) => void;
    onOpen: (id: number) => void;
}) {
    return (
        <tr
            onClick={() => onOpen(order.id)}
            className={[
                'cursor-pointer border-b border-border transition-colors last:border-0',
                selected ? 'bg-primary/5' : 'hover:bg-muted/40',
            ].join(' ')}
        >
            {/* The checkbox must not open the panel, and the panel must not
                tick the checkbox. */}
            <td className="px-3 py-2.5" onClick={(event) => event.stopPropagation()}>
                <input
                    type="checkbox"
                    checked={selected}
                    onChange={() => onToggle(order.id)}
                    aria-label={`Select order ${order.id}`}
                    className="size-4 cursor-pointer rounded border-border accent-primary"
                />
            </td>

            <td className="px-3 py-2.5">
                <span className="font-mono text-[0.8rem]">#{order.id}</span>
                {order.error && (
                    <TriangleAlert
                        className="ms-1.5 inline size-3.5 text-[#d03b3b]"
                        aria-label="The panel rejected this order"
                    />
                )}
            </td>

            <td className="px-3 py-2.5">
                <span className="font-mono text-[0.8rem]">{order.customer}</span>
            </td>

            <td className="max-w-0 px-3 py-2.5">
                <span className="block truncate" title={order.service ?? undefined}>
                    {order.service ?? '—'}
                </span>
            </td>

            <td className="px-3 py-2.5 text-right tabular-nums">
                {order.quantity !== null ? order.quantity.toLocaleString('en-US') : '—'}
            </td>

            <td className="px-3 py-2.5 text-right font-medium tabular-nums">
                {order.amount !== null ? formatMoney(order.amount) : '—'}
            </td>

            <td className="px-3 py-2.5">
                <PaymentBadge status={order.paymentStatus} />
            </td>

            <td className="px-3 py-2.5">
                <StatusBadge
                    status={order.status}
                    label={statusLabels[order.status]}
                    title={order.rawStatus}
                />
            </td>

            <td className="px-3 py-2.5 text-right text-xs text-muted-foreground">
                {formatShort(order.createdAt)}
            </td>
        </tr>
    );
}

function SortIcon({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
    if (!active) {
        return <ArrowDown className="size-3 opacity-0 transition-opacity group-hover:opacity-40" />;
    }

    return dir === 'asc' ? (
        <ArrowUp className="size-3" />
    ) : (
        <ArrowDown className="size-3" />
    );
}

function Pagination({
    meta,
    pageSizes,
    onPage,
    onPerPage,
}: {
    meta: OrdersPageProps['orders']['meta'];
    pageSizes: number[];
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
}) {
    return (
        <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs text-muted-foreground tabular-nums">
                {meta.from ?? 0}–{meta.to ?? 0} of {meta.total.toLocaleString('en-US')}
            </p>

            <div className="flex items-center gap-2">
                <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    Rows
                    <select
                        value={meta.perPage}
                        onChange={(event) => onPerPage(Number(event.target.value))}
                        className="h-8 rounded-lg border border-border bg-background px-1.5 text-xs outline-none focus:border-ring"
                    >
                        {pageSizes.map((size) => (
                            <option key={size} value={size}>
                                {size}
                            </option>
                        ))}
                    </select>
                </label>

                <div className="flex items-center gap-1">
                    <PageButton
                        disabled={meta.currentPage <= 1}
                        onClick={() => onPage(meta.currentPage - 1)}
                        label="Previous page"
                    >
                        <ChevronLeft className="size-4" />
                    </PageButton>

                    <span className="px-2 text-xs tabular-nums text-muted-foreground">
                        {meta.currentPage} / {meta.lastPage}
                    </span>

                    <PageButton
                        disabled={meta.currentPage >= meta.lastPage}
                        onClick={() => onPage(meta.currentPage + 1)}
                        label="Next page"
                    >
                        <ChevronRight className="size-4" />
                    </PageButton>
                </div>
            </div>
        </div>
    );
}

function PageButton({
    disabled,
    onClick,
    label,
    children,
}: {
    disabled: boolean;
    onClick: () => void;
    label: string;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            aria-label={label}
            className="rounded-lg border border-border p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
        >
            {children}
        </button>
    );
}

function EmptyState({
    isFiltered,
    onReset,
}: {
    isFiltered: boolean;
    onReset: () => void;
}) {
    return (
        <div className="rounded-xl border border-dashed border-border py-16 text-center">
            <Inbox className="mx-auto size-8 text-muted-foreground" />
            <p className="mt-3 font-semibold">
                {isFiltered ? 'No orders match those filters' : 'No orders yet'}
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
                {isFiltered
                    ? 'Try widening the date range or clearing the search.'
                    : 'Orders your bot takes will appear here.'}
            </p>
            {isFiltered && (
                <button
                    type="button"
                    onClick={onReset}
                    className="mt-4 text-sm text-primary underline-offset-4 hover:underline"
                >
                    Clear filters
                </button>
            )}
        </div>
    );
}

/** "Mar 4, 14:02" — long enough to identify, short enough for a table cell. */
function formatShort(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}
