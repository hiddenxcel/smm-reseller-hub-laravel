import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Search, X } from 'lucide-react';
import { useState } from 'react';
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
 * The status tabs carry the counts, so the four totals that used to sit above
 * the list are gone — they said the same thing twice.
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

    const countFor = (status: string): number | null =>
        status === '' ? null : counts[status as keyof TicketCounts];

    return (
        <AuthenticatedLayout>
            <Head title="Tickets — Support Bot" />

            <div className="mx-auto max-w-3xl space-y-4 sm:space-y-6">
                <header>
                    <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                        Tickets
                    </h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Support requests, opened by the bot or by your customers.
                    </p>
                </header>

                <div className="space-y-3">
                    <div
                        className="scroll-slim -mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0"
                        role="tablist"
                        aria-label="Filter by status"
                    >
                        {STATUS_FILTERS.map((status) => {
                            const active = filters.status === status;
                            const count = countFor(status);

                            return (
                                <button
                                    key={status}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    onClick={() => go({ status })}
                                    className={[
                                        'flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm transition-colors',
                                        active
                                            ? 'border-primary bg-primary/10 font-semibold text-foreground'
                                            : 'border-border text-muted-foreground hover:text-foreground',
                                    ].join(' ')}
                                >
                                    {STATUS_LABELS[status]}
                                    {count !== null && (
                                        <span className="font-data text-xs tabular-nums opacity-70">
                                            {count}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>

                    <div className="flex items-center gap-2">
                        <div className="relative min-w-0 flex-1">
                            <Search
                                className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <input
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                onKeyDown={(event) =>
                                    event.key === 'Enter' && go({ q: query.trim() })
                                }
                                placeholder="Search tickets"
                                aria-label="Search tickets"
                                className="h-10 w-full rounded-xl border border-border bg-background pl-9 pr-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                            />
                        </div>

                        <select
                            className="h-10 max-w-[9.5rem] shrink-0 rounded-xl border border-border bg-background px-2.5 text-sm outline-none focus:border-ring"
                            value={filters.category}
                            onChange={(event) => go({ category: event.target.value })}
                            aria-label="Filter by who opened it"
                        >
                            <option value="">Anyone</option>
                            <option value="human">Asked for a person</option>
                            <option value="ai">Opened by the bot</option>
                        </select>
                    </div>

                    {(filters.q !== '' || filters.category !== '' || filters.status !== '') && (
                        <button
                            type="button"
                            onClick={() => {
                                setQuery('');
                                router.get(route('support-bot.tickets'), {}, { replace: true });
                            }}
                            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <X className="size-3.5" aria-hidden />
                            Clear filters
                        </button>
                    )}
                </div>

                {rows.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                        No tickets match. They appear here when a customer asks for help.
                    </p>
                ) : (
                    <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                        {rows.map((ticket) => (
                            <li key={ticket.id}>
                                <Link
                                    href={route('support-bot.tickets.show', ticket.id)}
                                    className="flex items-center gap-3 p-4 transition-colors hover:bg-accent/50"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {ticket.subject ?? 'Support request'}
                                        </p>
                                        <p className="font-data mt-0.5 truncate text-xs text-muted-foreground">
                                            {ticket.customer ?? '—'}
                                            {ticket.orderRef && ` · order #${ticket.orderRef}`}
                                            {` · ${relativeTime(ticket.updatedAt)}`}
                                        </p>

                                        <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                            {ticket.handedOver && (
                                                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary">
                                                    Waiting for you
                                                </span>
                                            )}

                                            <span
                                                className={[
                                                    'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                                    ticket.category === 'human'
                                                        ? 'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]'
                                                        : 'bg-muted text-muted-foreground',
                                                ].join(' ')}
                                            >
                                                {ticket.category === 'human' ? 'Person' : 'Bot'}
                                            </span>

                                            <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium capitalize text-muted-foreground">
                                                {ticket.status}
                                            </span>
                                        </div>
                                    </div>

                                    <ChevronRight
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {lastPage > 1 && (
                    <div className="flex items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            Page {page} of {lastPage} · {total} tickets
                        </p>

                        <div className="flex gap-2">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => go({ page: String(page - 1) })}
                                className="rounded-xl border border-border px-3.5 py-2 text-sm disabled:opacity-50"
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                disabled={page >= lastPage}
                                onClick={() => go({ page: String(page + 1) })}
                                className="rounded-xl border border-border px-3.5 py-2 text-sm disabled:opacity-50"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
