import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    Download,
    Loader2,
    Plus,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    Avatar,
    BotChips,
    compact,
    money,
    prettyPhone,
    relativeTime,
    SegmentChips,
} from './bits';
import BulkBar from './BulkBar';
import CustomerForm from './CustomerForm';
import CustomerPanel from './CustomerPanel';
import FilterBar from './FilterBar';
import KpiRow from './KpiRow';
import { CustomerRow, CustomersPageProps, SortKey } from './types';
import { useCustomerFilters } from './useCustomerFilters';

const COLUMNS: Array<{
    key: SortKey | null;
    label: string;
    align?: 'right';
    className?: string;
    /** Columns that fold away first as the viewport narrows. */
    hideBelow?: 'lg' | 'xl';
}> = [
    { key: 'name', label: 'Customer' },
    { key: 'phone', label: 'WhatsApp', className: 'w-[11rem]' },
    { key: null, label: 'Bot', className: 'w-[9rem]', hideBelow: 'xl' },
    { key: 'orders', label: 'Orders', align: 'right', className: 'w-[6rem]' },
    { key: 'total_spent', label: 'Spent', align: 'right', className: 'w-[7rem]' },
    { key: 'balance', label: 'Wallet', align: 'right', className: 'w-[7rem]' },
    { key: null, label: 'Status', className: 'w-[8rem]', hideBelow: 'lg' },
    { key: 'last_seen_at', label: 'Last seen', align: 'right', className: 'w-[8rem]' },
    { key: 'created_at', label: 'Joined', align: 'right', className: 'w-[7rem]', hideBelow: 'xl' },
];

export default function Customers({
    customers,
    filters,
    isFiltered,
    kpis,
    tabCounts,
    options,
    pageSizes,
    bulkLimits,
    hasWhatsApp,
}: CustomersPageProps) {
    const { apply, sortBy, reset, busy } = useCustomerFilters(filters);

    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [selectedBeyondPage, setSelectedBeyondPage] = useState(false);
    const [openId, setOpenId] = useState<number | null>(null);
    const [editing, setEditing] = useState<CustomerRow | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [acting, setActing] = useState(false);

    const rows = customers.data;
    const meta = customers.meta;

    // A selection only means anything against the rows it was made on.
    // Changing filter or page can drop matching rows out of view, and acting on
    // ids the reseller can no longer see is what a bulk action must never do.
    useEffect(() => {
        setSelected(new Set());
        setSelectedBeyondPage(false);
    }, [filters, meta.currentPage]);

    const openCustomer = useMemo(
        () => rows.find((row) => row.id === openId) ?? null,
        [rows, openId],
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
        setSelected((current) =>
            pageIds.every((id) => current.has(id)) ? new Set() : new Set(pageIds),
        );
    }, [pageIds]);

    const selectAllMatching = useCallback(async () => {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');

        const response = await fetch(
            `${route('customers.matching-ids')}?${params.toString()}`,
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
        (
            customer: CustomerRow,
            action: 'wallet' | 'block' | 'unblock' | 'message',
            payload: { amount?: string; reason?: string; text?: string } = {},
        ) => {
            setActing(true);

            router.post(
                route('customers.act', customer.id),
                { action, ...payload },
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
        (
            action: 'block' | 'unblock' | 'tag' | 'untag' | 'broadcast',
            payload: { tag?: string; text?: string } = {},
        ) => {
            setActing(true);

            router.post(
                route('customers.bulk'),
                { action, ids: Array.from(selected), ...payload },
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

    const deleteCustomer = useCallback((customer: CustomerRow) => {
        setActing(true);

        router.delete(route('customers.destroy', customer.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setOpenId(null),
            onFinish: () => setActing(false),
        });
    }, []);

    return (
        <AuthenticatedLayout bleed>
            <Head title="Customers" />

            <div className="sticky top-0 z-30 border-b border-border bg-background/95 px-4 pb-3 pt-5 backdrop-blur sm:px-8">
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-2xl font-extrabold tracking-tight">
                            Customers
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Everyone who has messaged your Order Bot or Support Bot.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {busy && (
                            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Loader2 className="size-3.5 animate-spin" aria-hidden />
                                Updating
                            </span>
                        )}

                        <a
                            href={route('customers.export', currentQuery())}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm transition-colors hover:bg-accent"
                        >
                            <Download className="size-3.5" aria-hidden />
                            Export
                        </a>

                        <Button
                            size="sm"
                            className="h-9"
                            onClick={() => {
                                setEditing(null);
                                setFormOpen(true);
                            }}
                        >
                            <Plus className="size-3.5" />
                            Add customer
                        </Button>
                    </div>
                </div>

                <KpiRow kpis={kpis} />

                <div className="mt-4">
                    <FilterBar
                        filters={filters}
                        tabCounts={tabCounts}
                        options={options}
                        isFiltered={isFiltered}
                        onApply={apply}
                        onReset={reset}
                    />
                </div>
            </div>

            <div className="px-4 pb-24 pt-4 sm:px-8">
                {rows.length === 0 ? (
                    <EmptyState isFiltered={isFiltered} onReset={reset} />
                ) : (
                    <>
                        {/* Below `md` the table becomes cards: nine columns on a
                            phone is a horizontal scroll nobody reads. */}
                        <div className="space-y-2 md:hidden">
                            {rows.map((customer) => (
                                <MobileCard
                                    key={customer.id}
                                    customer={customer}
                                    selected={selected.has(customer.id)}
                                    onToggle={toggleRow}
                                    onOpen={setOpenId}
                                />
                            ))}
                        </div>

                        <div className="hidden overflow-x-auto rounded-xl border border-border md:block">
                            <table className="w-full min-w-[52rem] border-collapse text-sm">
                                <thead>
                                    <tr className="border-b border-border bg-muted/40 text-left">
                                        <th scope="col" className="w-10 px-3 py-2.5">
                                            <input
                                                type="checkbox"
                                                checked={allOnPageSelected}
                                                onChange={toggleAllOnPage}
                                                aria-label="Select every customer on this page"
                                                className="size-4 cursor-pointer rounded border-border accent-primary"
                                            />
                                        </th>

                                        {COLUMNS.map((column) => (
                                            <th
                                                key={column.label}
                                                scope="col"
                                                className={[
                                                    'px-3 py-2.5 text-xs font-semibold text-muted-foreground',
                                                    column.align === 'right' ? 'text-right' : '',
                                                    column.hideBelow === 'lg'
                                                        ? 'hidden lg:table-cell'
                                                        : '',
                                                    column.hideBelow === 'xl'
                                                        ? 'hidden xl:table-cell'
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
                                    {rows.map((customer) => (
                                        <Row
                                            key={customer.id}
                                            customer={customer}
                                            selected={selected.has(customer.id)}
                                            onToggle={toggleRow}
                                            onOpen={setOpenId}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <Pagination
                            meta={meta}
                            pageSizes={pageSizes}
                            onPage={(page) => apply({ page })}
                            onPerPage={(perPage) => apply({ perPage, page: 1 })}
                        />
                    </>
                )}
            </div>

            <BulkBar
                count={selected.size}
                totalMatching={meta.total}
                allSelected={selectedBeyondPage}
                limits={bulkLimits}
                hasWhatsApp={hasWhatsApp}
                pending={acting}
                onAction={runBulk}
                onSelectAllMatching={selectAllMatching}
                onClear={() => {
                    setSelected(new Set());
                    setSelectedBeyondPage(false);
                }}
            />

            <CustomerPanel
                customer={openCustomer}
                pending={acting}
                onClose={() => setOpenId(null)}
                onAction={runAction}
                onEdit={(customer) => {
                    setEditing(customer);
                    setFormOpen(true);
                }}
                onDelete={deleteCustomer}
            />

            <CustomerForm
                open={formOpen}
                customer={editing}
                onClose={() => setFormOpen(false)}
            />
        </AuthenticatedLayout>
    );
}

function Row({
    customer,
    selected,
    onToggle,
    onOpen,
}: {
    customer: CustomerRow;
    selected: boolean;
    onToggle: (id: number) => void;
    onOpen: (id: number) => void;
}) {
    return (
        <tr
            onClick={() => onOpen(customer.id)}
            className={[
                'cursor-pointer border-b border-border transition-colors last:border-0',
                selected ? 'bg-primary/5' : 'hover:bg-muted/40',
                customer.blocked ? 'opacity-60' : '',
            ].join(' ')}
        >
            {/* The checkbox must not open the panel, and the panel must not
                tick the checkbox. */}
            <td className="px-3 py-2.5" onClick={(event) => event.stopPropagation()}>
                <input
                    type="checkbox"
                    checked={selected}
                    onChange={() => onToggle(customer.id)}
                    aria-label={`Select ${customer.name ?? customer.phone}`}
                    className="size-4 cursor-pointer rounded border-border accent-primary"
                />
            </td>

            <td className="max-w-0 px-3 py-2.5">
                <div className="flex items-center gap-2.5">
                    <Avatar name={customer.name} phone={customer.phone} size="sm" />
                    <div className="min-w-0">
                        <p className="truncate font-medium">
                            {customer.name ?? 'Unnamed'}
                        </p>
                        {customer.email && (
                            <p className="truncate text-xs text-muted-foreground">
                                {customer.email}
                            </p>
                        )}
                    </div>
                </div>
            </td>

            <td className="font-data px-3 py-2.5 text-[0.82rem]">
                {prettyPhone(customer.phone)}
            </td>

            <td className="hidden px-3 py-2.5 xl:table-cell">
                <BotChips bots={customer.bots} />
            </td>

            <td className="font-data px-3 py-2.5 text-right tabular-nums">
                {compact(customer.orders)}
            </td>

            <td className="font-data px-3 py-2.5 text-right tabular-nums">
                {money(customer.spent)}
            </td>

            <td className="font-data px-3 py-2.5 text-right font-medium tabular-nums">
                {money(customer.balance)}
            </td>

            <td className="hidden px-3 py-2.5 lg:table-cell">
                <SegmentChips segments={customer.segments} limit={1} />
            </td>

            <td className="px-3 py-2.5 text-right text-xs text-muted-foreground">
                {relativeTime(customer.lastSeenAt)}
            </td>

            <td className="hidden px-3 py-2.5 text-right text-xs text-muted-foreground xl:table-cell">
                {relativeTime(customer.createdAt)}
            </td>
        </tr>
    );
}

function MobileCard({
    customer,
    selected,
    onToggle,
    onOpen,
}: {
    customer: CustomerRow;
    selected: boolean;
    onToggle: (id: number) => void;
    onOpen: (id: number) => void;
}) {
    return (
        <div
            onClick={() => onOpen(customer.id)}
            className={[
                'cursor-pointer rounded-xl border p-3 transition-colors',
                selected ? 'border-primary/40 bg-primary/5' : 'border-border',
                customer.blocked ? 'opacity-60' : '',
            ].join(' ')}
        >
            <div className="flex items-start gap-3">
                <div onClick={(event) => event.stopPropagation()} className="pt-0.5">
                    <input
                        type="checkbox"
                        checked={selected}
                        onChange={() => onToggle(customer.id)}
                        aria-label={`Select ${customer.name ?? customer.phone}`}
                        className="size-4 cursor-pointer rounded border-border accent-primary"
                    />
                </div>

                <Avatar name={customer.name} phone={customer.phone} />

                <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{customer.name ?? 'Unnamed'}</p>
                    <p className="font-data truncate text-xs text-muted-foreground">
                        {prettyPhone(customer.phone)}
                    </p>

                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <SegmentChips segments={customer.segments} limit={2} />
                        <BotChips bots={customer.bots} />
                    </div>
                </div>

                <div className="shrink-0 text-right">
                    <p className="font-data text-sm font-semibold tabular-nums">
                        {money(customer.balance)}
                    </p>
                    <p className="font-data text-xs text-muted-foreground tabular-nums">
                        {compact(customer.orders)} orders
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {relativeTime(customer.lastSeenAt)}
                    </p>
                </div>
            </div>
        </div>
    );
}

function SortIcon({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
    if (!active) {
        return <ArrowDown className="size-3 opacity-0" aria-hidden />;
    }

    return dir === 'asc' ? (
        <ArrowUp className="size-3" aria-hidden />
    ) : (
        <ArrowDown className="size-3" aria-hidden />
    );
}

function Pagination({
    meta,
    pageSizes,
    onPage,
    onPerPage,
}: {
    meta: CustomersPageProps['customers']['meta'];
    pageSizes: number[];
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
}) {
    return (
        <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p className="font-data text-xs text-muted-foreground tabular-nums">
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

                    <span className="font-data px-2 text-xs tabular-nums text-muted-foreground">
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
        <div className="rounded-xl border border-dashed border-border py-20 text-center">
            <span
                className="mx-auto flex size-14 items-center justify-center rounded-full bg-muted"
                aria-hidden
            >
                <Users className="size-6 text-muted-foreground" />
            </span>

            <p className="mt-4 font-semibold">
                {isFiltered ? 'No customers match those filters' : 'No customers yet'}
            </p>
            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                {isFiltered
                    ? 'Try widening the date range or clearing the search.'
                    : 'Customers appear here the first time someone messages your bot.'}
            </p>

            {isFiltered ? (
                <button
                    type="button"
                    onClick={onReset}
                    className="mt-4 text-sm text-primary underline-offset-4 hover:underline"
                >
                    Clear filters
                </button>
            ) : (
                <Button size="sm" className="mt-4" asChild>
                    <a href={route('onboarding')}>Connect your Order Bot</a>
                </Button>
            )}
        </div>
    );
}

/** The current query string, so Export downloads exactly what is on screen. */
function currentQuery(): Record<string, string> {
    const params = new URLSearchParams(window.location.search);
    params.delete('page');

    return Object.fromEntries(params.entries());
}
