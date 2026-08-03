import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { Metric, inputClass } from '../OrderBot/bits';
import { relativeTime } from '../OrderBot/inbox-bits';
import { TicketCounts, TicketRow } from './types';

const STATUS_FILTERS = ['', 'open', 'pending', 'resolved', 'closed'] as const;

const STATUS_LABELS: Record<string, string> = {
    '': 'All',
    open: 'Open',
    pending: 'Pending',
    resolved: 'Resolved',
    closed: 'Closed',
};

/**
 * Every support request in one list, whoever opened it.
 *
 * Tickets arrive two ways — the bot logs one when it cannot finish a job
 * itself (a refill it submitted, a partial-completion report), and a customer
 * asking for a person opens one directly. A reseller working through their
 * backlog wants both in one place, so category is a filter here rather than a
 * separate screen.
 *
 * Handed-over tickets sort to the top: somebody is waiting on a person for
 * those, and nothing else on this page is time-critical.
 */
export default function SupportBotTickets({
    counts,
    filters,
    rows,
    page,
    lastPage,
    total,
}: {
    counts: TicketCounts;
    filters: { status: string; category: string; q: string };
    rows: TicketRow[];
    page: number;
    lastPage: number;
    total: number;
}) {
    const [query, setQuery] = useState(filters.q);

    /**
     * Everything about the view lives in the query string, so a filtered list
     * is a shareable URL. Empty values are dropped rather than sent blank —
     * `?status=` in a shared link reads as a filter that is set to nothing.
     *
     * Changing a filter resets to page 1 unless the change IS the page: the
     * old page number rarely exists in the new, smaller result set.
     */
    const go = (changes: Record<string, string>) => {
        const next: Record<string, string> = {
            ...filters,
            ...(changes.page === undefined ? { page: '' } : {}),
            ...changes,
        };

        router.get(
            route('support-bot.tickets'),
            Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '')),
            { preserveState: true, replace: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-bold">Tickets</h1>
                    <p className="text-sm text-muted-foreground">
                        Support requests from your customers, opened by the bot or by them.
                    </p>
                </div>
            }
        >
            <Head title="Tickets — Support Bot" />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Metric label="Open" value={String(counts.open)} />
                <Metric label="Pending" value={String(counts.pending)} />
                <Metric label="Resolved" value={String(counts.resolved)} />
                <Metric label="Closed" value={String(counts.closed)} />
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <div className="flex flex-wrap gap-1">
                    {STATUS_FILTERS.map((status) => (
                        <button
                            key={status}
                            type="button"
                            onClick={() => go({ status })}
                            className={[
                                'rounded-lg px-3 py-1.5 text-sm transition-colors',
                                filters.status === status
                                    ? 'bg-primary text-primary-foreground'
                                    : 'border border-input hover:bg-accent',
                            ].join(' ')}
                            aria-pressed={filters.status === status}
                        >
                            {STATUS_LABELS[status]}
                        </button>
                    ))}
                </div>

                <select
                    className={`${inputClass} w-auto`}
                    value={filters.category}
                    onChange={(event) => go({ category: event.target.value })}
                    aria-label="Filter by who opened it"
                >
                    <option value="">Anyone</option>
                    <option value="human">Asked for a person</option>
                    <option value="ai">Opened by the bot</option>
                </select>

                <div className="relative min-w-56 flex-1">
                    <Search
                        className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => event.key === 'Enter' && go({ q: query.trim() })}
                        placeholder="Search number, subject or order"
                        aria-label="Search tickets"
                        className={`${inputClass} pl-9`}
                    />
                </div>
            </div>

            {rows.length === 0 ? (
                <p className="rounded-xl border border-border bg-card p-8 text-center text-sm text-muted-foreground">
                    No tickets match. They appear here when a customer asks for help.
                </p>
            ) : (
                <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                    {rows.map((ticket) => (
                        <li key={ticket.id}>
                            <Link
                                href={route('support-bot.tickets.show', ticket.id)}
                                className="flex flex-wrap items-center gap-3 p-4 transition-colors hover:bg-accent/60"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {ticket.subject ?? 'Support request'}
                                    </p>
                                    <p className="font-data mt-0.5 truncate text-xs text-muted-foreground">
                                        {ticket.customer ?? '—'}
                                        {ticket.orderRef && ` · order #${ticket.orderRef}`}
                                        {ticket.messages > 0 &&
                                            ` · ${ticket.messages} message${ticket.messages === 1 ? '' : 's'}`}
                                    </p>
                                </div>

                                <div className="flex shrink-0 flex-wrap items-center gap-2">
                                    {ticket.handedOver && (
                                        <span className="rounded bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary">
                                            Waiting for you
                                        </span>
                                    )}

                                    <span
                                        className={[
                                            'rounded px-2 py-0.5 text-[11px] font-medium',
                                            ticket.category === 'human'
                                                ? 'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]'
                                                : 'bg-muted text-muted-foreground',
                                        ].join(' ')}
                                    >
                                        {ticket.category === 'human' ? 'Person' : 'Bot'}
                                    </span>

                                    <span className="rounded bg-muted px-2 py-0.5 text-[11px] font-medium capitalize text-muted-foreground">
                                        {ticket.status}
                                    </span>

                                    <span className="w-16 text-right text-xs text-muted-foreground">
                                        {relativeTime(ticket.updatedAt)}
                                    </span>
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {lastPage > 1 && (
                <div className="mt-4 flex items-center justify-between gap-3">
                    <p className="text-sm text-muted-foreground">
                        Page {page} of {lastPage} · {total} tickets
                    </p>

                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={page <= 1}
                            onClick={() => go({ page: String(page - 1) })}
                            className="rounded-lg border border-input px-3 py-1.5 text-sm disabled:opacity-50"
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            disabled={page >= lastPage}
                            onClick={() => go({ page: String(page + 1) })}
                            className="rounded-lg border border-input px-3 py-1.5 text-sm disabled:opacity-50"
                        >
                            Next
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
