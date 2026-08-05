import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    DataTable,
    dateTime,
    Empty,
    FilterTabs,
    Pagination,
    pushFilters,
    TabsSkeleton,
} from '../bits';
import {
    PageMeta,
    TicketFilterState,
    TicketMessageRow,
    TicketRow,
} from '../types';

type Props = {
    tickets: { data: TicketRow[]; meta: PageMeta };
    filters: TicketFilterState;
    isFiltered: boolean;
    tabCounts?: Record<string, number>;
    busiest?: Array<{ tenantId: number; tenant: string; open: number }>;
};

const ROUTE = 'admin.tickets.index';

export default function TicketsIndex({
    tickets,
    filters,
    isFiltered,
    tabCounts,
    busiest,
}: Props) {
    const [open, setOpen] = useState<number | null>(null);

    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">
                        Support tickets
                    </h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Every reseller's tickets. Read-only — replying would put our
                        words in their WhatsApp thread.
                    </p>
                </div>
            }
        >
            <Head title="Tickets — Control" />

            <Deferred data="busiest" fallback={<span />}>
                {busiest && busiest.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {busiest.map((row) => (
                            <button
                                key={row.tenantId}
                                type="button"
                                onClick={() =>
                                    pushFilters(ROUTE, { ...filters, tenant: row.tenantId })
                                }
                                className={[
                                    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm transition-colors',
                                    filters.tenant === row.tenantId
                                        ? 'border-primary bg-accent font-semibold'
                                        : 'border-border hover:bg-accent',
                                ].join(' ')}
                            >
                                <span className="truncate">{row.tenant}</span>
                                <span className="tabular-nums text-muted-foreground">
                                    {row.open}
                                </span>
                            </button>
                        ))}
                    </div>
                )}
            </Deferred>

            <div className="mt-4 flex flex-wrap items-center gap-2">
                <SearchBox filters={filters} />

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
                        { key: 'open', label: 'Open', count: tabCounts?.open },
                        { key: 'pending', label: 'Pending', count: tabCounts?.pending },
                        {
                            key: 'handed_over',
                            label: 'With a person',
                            count: tabCounts?.handed_over,
                        },
                        {
                            key: 'resolved',
                            label: 'Resolved',
                            count: tabCounts?.resolved,
                        },
                        { key: 'closed', label: 'Closed', count: tabCounts?.closed },
                    ]}
                />
            </Deferred>

            <DataTable
                headers={[
                    { label: 'Reseller' },
                    { label: 'Customer' },
                    { label: 'Subject' },
                    { label: 'Status' },
                    { label: 'Opened' },
                ]}
            >
                {tickets.data.map((row) => (
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
                        <td className="px-4 py-3 text-muted-foreground">
                            {row.customer ?? '—'}
                        </td>
                        <td className="px-4 py-3">
                            <span className="block max-w-[18rem] truncate">
                                {row.subject ?? '—'}
                            </span>
                            {row.category && (
                                <span className="text-xs text-muted-foreground">
                                    {row.category}
                                </span>
                            )}
                        </td>
                        <td className="px-4 py-3">
                            <StatusChip status={row.status} />
                            {row.handedOver && (
                                <span
                                    className={[
                                        'mt-1 flex items-center gap-1 text-[10px]',
                                        row.stalled
                                            ? 'text-destructive'
                                            : 'text-amber-700 dark:text-amber-300',
                                    ].join(' ')}
                                    title={
                                        row.stalled
                                            ? 'Handed to a person over a day ago and still open'
                                            : 'A person has claimed this; the bot is silent'
                                    }
                                >
                                    <AlertTriangle className="size-3" />
                                    {row.stalled ? 'Waiting >24h' : 'With a person'}
                                </span>
                            )}
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">
                            {dateTime(row.at)}
                        </td>
                    </tr>
                ))}
            </DataTable>

            {tickets.data.length === 0 && (
                <Empty>
                    {isFiltered
                        ? 'No tickets match these filters.'
                        : 'No tickets yet.'}
                </Empty>
            )}

            <Pagination
                meta={tickets.meta}
                onPage={(page) => pushFilters(ROUTE, filters, page)}
            />

            {open !== null && (
                <ThreadPanel id={open} onClose={() => setOpen(null)} />
            )}
        </AdminLayout>
    );
}

function SearchBox({ filters }: { filters: TicketFilterState }) {
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
                placeholder="Search subject, customer phone or reseller"
                className="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            />
        </div>
    );
}

function StatusChip({ status }: { status: string }) {
    const styles: Record<string, string> = {
        open: 'bg-primary/10 text-primary',
        pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        resolved:
            'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]',
        closed: 'bg-muted text-muted-foreground',
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
 * One ticket's thread, read-only.
 *
 * No reply box on purpose: this conversation runs on the reseller's number and
 * under their business name. Staff answer it from their own inbox.
 */
function ThreadPanel({ id, onClose }: { id: number; onClose: () => void }) {
    const [data, setData] = useState<{
        ticket: TicketRow;
        messages: TicketMessageRow[];
    } | null>(null);

    useEffect(() => {
        let cancelled = false;

        window.axios.get(route('admin.tickets.show', id)).then((response) => {
            if (!cancelled) {
                setData(response.data);
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
                {data === null ? (
                    <div className="space-y-3">
                        {[0, 1, 2, 3].map((i) => (
                            <div key={i} className="h-6 animate-pulse rounded bg-muted" />
                        ))}
                    </div>
                ) : (
                    <>
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <h2 className="font-heading truncate text-lg font-extrabold">
                                    {data.ticket.subject ?? 'Ticket'}
                                </h2>
                                <p className="truncate text-sm text-muted-foreground">
                                    {data.ticket.tenant} · {data.ticket.customer}
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

                        <div className="mt-3 flex flex-wrap gap-2">
                            <StatusChip status={data.ticket.status} />
                            {data.ticket.handedOver && (
                                <span className="rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                    With a person since{' '}
                                    {dateTime(data.ticket.handedOverAt)}
                                </span>
                            )}
                        </div>

                        <div className="mt-5 space-y-3">
                            {data.messages.length === 0 ? (
                                <Empty>No messages on this ticket.</Empty>
                            ) : (
                                data.messages.map((message) => (
                                    <div
                                        key={message.id}
                                        className="rounded-lg border border-border px-3 py-2"
                                    >
                                        <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                            <span className="font-medium capitalize">
                                                {message.sender ?? 'unknown'}
                                            </span>
                                            <span>{dateTime(message.at)}</span>
                                        </div>
                                        <p className="mt-1 whitespace-pre-line text-sm">
                                            {message.message}
                                        </p>
                                    </div>
                                ))
                            )}
                        </div>

                        <p className="mt-6 border-t border-border pt-4 text-xs text-muted-foreground">
                            Read-only. To answer, open this reseller's own inbox by
                            viewing their account.
                        </p>

                        <Link
                            href={route('admin.tenants.show', data.ticket.tenantId)}
                            className="mt-2 inline-block text-sm font-medium text-primary hover:underline"
                        >
                            Go to {data.ticket.tenant} →
                        </Link>
                    </>
                )}
            </div>
        </div>
    );
}
