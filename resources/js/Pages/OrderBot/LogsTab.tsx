import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
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
    const [who, setWho] = useState<'all' | 'in' | 'out'>('all');

    // Narrows the page already loaded; searching still goes to the server.
    const rows = data.rows.filter((row) => who === 'all' || row.direction === who);

    const search = () =>
        router.get(
            route('order-bot', 'logs'),
            query.trim() === '' ? {} : { q: query.trim() },
            { preserveState: true, replace: true },
        );

    return (
        <div className="space-y-3 sm:space-y-4">
            <div className="flex items-center gap-2">
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

            <div className="flex items-center justify-between gap-3">
                <div className="inline-flex rounded-lg bg-muted p-0.5 text-xs font-medium">
                    {(
                        [
                            ['all', 'All'],
                            ['in', 'Customers'],
                            ['out', 'Bot'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setWho(key)}
                            className={`rounded-md px-3 py-1.5 transition-colors ${
                                who === key
                                    ? 'bg-card shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <p className="text-xs text-muted-foreground">
                    {data.total.toLocaleString('en-US')}{' '}
                    {data.total === 1 ? 'message' : 'messages'}
                    {data.q !== '' && ` for “${data.q}”`}
                </p>
            </div>

            {rows.length === 0 ? (
                <p className="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                    {data.q === ''
                        ? 'Nothing yet. Messages to this bot will appear here.'
                        : 'No messages match that search.'}
                </p>
            ) : (
                <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                    {rows.map((row) => (
                        <MessageRow key={row.id} row={row} />
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

/**
 * One message. A long reply (a menu, an order receipt) is cut to a few lines so
 * the list stays scannable; a tap opens it in full.
 */
function MessageRow({ row }: { row: Logs['rows'][number] }) {
    const [open, setOpen] = useState(false);
    const text = row.message ?? '—';
    const long = text.length > 140 || text.split('\n').length > 3;
    const fromBot = row.direction === 'out';

    return (
        <li className="p-3.5">
            <div className="flex items-baseline justify-between gap-3">
                <span className="flex min-w-0 items-center gap-2">
                    <span
                        className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${
                            fromBot ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
                        }`}
                    >
                        {fromBot ? 'Bot' : 'Customer'}
                    </span>
                    <span className="font-data truncate text-sm">{row.phone}</span>
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">{formatTime(row.at)}</span>
            </div>

            <p
                className={`mt-1.5 whitespace-pre-wrap break-words text-sm ${
                    fromBot ? 'text-muted-foreground' : ''
                } ${long && !open ? 'line-clamp-3' : ''}`}
            >
                {text}
            </p>

            {long && (
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    className="mt-1 text-xs font-semibold text-primary hover:underline"
                >
                    {open ? 'Show less' : 'Show more'}
                </button>
            )}
        </li>
    );
}
