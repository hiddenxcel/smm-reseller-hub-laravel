import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, Search } from 'lucide-react';
import { useState } from 'react';
import { inputClass } from './bits';
import { Logs } from './types';

/**
 * Every message this bot saw, newest first.
 *
 * Inbound messages are logged before the subscription gate and the spam check,
 * so a message that was refused still shows up here — which is exactly the one
 * a reseller asking "did they ever message me?" is looking for.
 */
export function LogsTab({ data }: { data: Logs }) {
    const [query, setQuery] = useState(data.q);

    const search = () =>
        router.get(
            route('order-bot', 'logs'),
            query.trim() === '' ? {} : { q: query.trim() },
            { preserveState: true, replace: true },
        );

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-0 flex-1">
                    <Search
                        className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => event.key === 'Enter' && search()}
                        placeholder="Search by number or message"
                        aria-label="Search messages"
                        className={`${inputClass} pl-9`}
                    />
                </div>
                <Button type="button" variant="outline" onClick={search}>
                    Search
                </Button>
            </div>

            <p className="text-sm text-muted-foreground">
                {data.total.toLocaleString('en-US')}{' '}
                {data.total === 1 ? 'message' : 'messages'}
                {data.q !== '' && ` matching “${data.q}”`}
            </p>

            {data.rows.length === 0 ? (
                <p className="rounded-xl border border-border bg-card p-8 text-center text-sm text-muted-foreground">
                    {data.q === ''
                        ? 'Nothing yet. Messages to this bot will appear here.'
                        : 'No messages match that search.'}
                </p>
            ) : (
                <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                    {data.rows.map((row) => (
                        <li key={row.id} className="flex gap-3 p-3">
                            {row.direction === 'in' ? (
                                <ArrowDownLeft
                                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    aria-label="Received"
                                />
                            ) : (
                                <ArrowUpRight
                                    className="mt-0.5 size-4 shrink-0 text-primary"
                                    aria-label="Sent"
                                />
                            )}

                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <span className="font-data text-sm">{row.phone}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {formatTime(row.at)}
                                    </span>
                                </div>
                                <p className="mt-0.5 whitespace-pre-wrap break-words text-sm text-muted-foreground">
                                    {row.message ?? '—'}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {data.lastPage > 1 && (
                <div className="flex items-center justify-between gap-3">
                    <PageLink page={data.page - 1} q={data.q} disabled={data.page <= 1}>
                        Previous
                    </PageLink>
                    <span className="text-sm text-muted-foreground">
                        Page {data.page} of {data.lastPage}
                    </span>
                    <PageLink
                        page={data.page + 1}
                        q={data.q}
                        disabled={data.page >= data.lastPage}
                    >
                        Next
                    </PageLink>
                </div>
            )}
        </div>
    );
}

function PageLink({
    page,
    q,
    disabled,
    children,
}: {
    page: number;
    q: string;
    disabled: boolean;
    children: string;
}) {
    const classes = 'rounded-lg border border-border px-3 py-1.5 text-sm transition-colors';

    if (disabled) {
        return (
            <span className={`${classes} cursor-not-allowed text-muted-foreground/50`}>
                {children}
            </span>
        );
    }

    return (
        <Link
            href={route('order-bot', 'logs')}
            data={q === '' ? { page } : { page, q }}
            preserveState
            className={`${classes} hover:bg-accent`}
        >
            {children}
        </Link>
    );
}

function formatTime(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
