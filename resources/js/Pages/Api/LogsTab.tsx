import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';
import { Card, EmptyState, dateTime } from './bits';
import { ApiKeyRow, LogRow, Paginated } from './types';

/**
 * What the keys have actually been doing.
 *
 * This is the answer to "it isn't working" — a sentence a reseller will hear
 * from a customer whose code is on a server they cannot see. Failures are
 * filterable in one click because that is what the screen is opened for.
 */
export function LogsTab({
    logs,
    filters,
    keys,
}: {
    logs: Paginated<LogRow>;
    filters: { key: string; failed: boolean };
    keys: ApiKeyRow[];
}) {
    const apply = (next: Partial<{ key: string; failed: boolean }>) => {
        const query: Record<string, string> = {};
        const key = next.key ?? filters.key;
        const failed = next.failed ?? filters.failed;

        if (key) {
            query.key = key;
        }

        if (failed) {
            query.failed = '1';
        }

        router.get(route('api-access', 'logs'), query, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <Card
            title="Requests"
            description="Every call, including the ones that were turned away. Newest first."
        >
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant={filters.failed ? 'default' : 'outline'}
                    size="sm"
                    onClick={() => apply({ failed: !filters.failed })}
                >
                    Failures only
                </Button>

                {keys.length > 0 && (
                    <select
                        value={filters.key}
                        onChange={(event) => apply({ key: event.target.value })}
                        className="h-7 rounded-lg border border-border bg-background px-2 text-[0.8rem]"
                        aria-label="Filter by key"
                    >
                        <option value="">All keys</option>
                        {keys.map((key) => (
                            <option key={key.id} value={key.id}>
                                {key.label || `${key.prefix}…`}
                            </option>
                        ))}
                    </select>
                )}

                {(filters.failed || filters.key) && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.get(route('api-access', 'logs'), {}, { preserveScroll: true })
                        }
                    >
                        Clear
                    </Button>
                )}

                <span className="ml-auto text-xs text-muted-foreground">
                    {logs.total.toLocaleString()} request{logs.total === 1 ? '' : 's'}
                </span>
            </div>

            {logs.data.length === 0 ? (
                <EmptyState>
                    {filters.failed || filters.key
                        ? 'Nothing matches that filter.'
                        : 'No API requests yet. They will appear here as soon as a key is used.'}
                </EmptyState>
            ) : (
                <>
                    <ul className="divide-y divide-border">
                        {logs.data.map((log) => (
                            <LogEntry key={log.id} log={log} />
                        ))}
                    </ul>

                    <Pagination logs={logs} />
                </>
            )}
        </Card>
    );
}

function LogEntry({ log }: { log: LogRow }) {
    return (
        <li className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
            {log.ok ? (
                <CheckCircle2
                    className="mt-0.5 size-4 shrink-0 text-[#006300] dark:text-[#0ca30c]"
                    aria-label="succeeded"
                />
            ) : (
                <XCircle className="mt-0.5 size-4 shrink-0 text-destructive" aria-label="failed" />
            )}

            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <code className="font-mono text-sm font-medium">{log.action ?? '—'}</code>

                    {log.key && (
                        <span className="text-xs text-muted-foreground">
                            {log.key.label || `${log.key.prefix}…`}
                        </span>
                    )}

                    <span className="text-xs text-muted-foreground">{dateTime(log.createdAt)}</span>

                    {log.durationMs !== null && (
                        <span className="text-xs text-muted-foreground">{log.durationMs}ms</span>
                    )}

                    {log.ip && (
                        <span className="font-mono text-xs text-muted-foreground">{log.ip}</span>
                    )}
                </div>

                {/* The reason is the whole point of the row when it failed, so
                    it is given its own line rather than being tucked into the
                    metadata above. */}
                {log.error && <p className="mt-1 text-sm text-destructive">{log.error}</p>}

                {log.ok && log.details && Object.keys(log.details).length > 0 && (
                    <p className="mt-1 font-mono text-xs text-muted-foreground">
                        {Object.entries(log.details)
                            .map(([name, value]) => `${name}=${String(value)}`)
                            .join('  ')}
                    </p>
                )}
            </div>
        </li>
    );
}

function Pagination({ logs }: { logs: Paginated<LogRow> }) {
    if (logs.links.length <= 3) {
        return null;
    }

    return (
        <nav className="mt-4 flex flex-wrap items-center gap-1 border-t border-border pt-4">
            {logs.links.map((link, index) => {
                const label = link.label
                    .replace('&laquo; Previous', '←')
                    .replace('Next &raquo;', '→');

                if (link.url === null) {
                    return (
                        <span
                            key={index}
                            className="px-2.5 py-1 text-sm text-muted-foreground"
                            dangerouslySetInnerHTML={{ __html: label }}
                        />
                    );
                }

                return (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        className={[
                            'rounded-lg px-2.5 py-1 text-sm transition-colors',
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        ].join(' ')}
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                );
            })}
        </nav>
    );
}
