import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    dateTime,
    Empty,
    FilterTabs,
    Pagination,
    pushFilters,
    TabsSkeleton,
} from '../bits';
import { AuditEntry, AuditFilterState, PageMeta } from '../types';

type Props = {
    entries: { data: AuditEntry[]; meta: PageMeta };
    filters: AuditFilterState;
    isFiltered: boolean;
    tabCounts?: Record<string, number>;
    actions?: string[];
};

const ROUTE = 'admin.audit';

export default function Audit({
    entries,
    filters,
    isFiltered,
    tabCounts,
    actions,
}: Props) {
    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Audit log</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Who did what, and when. Nothing here can be edited or
                        deleted.
                    </p>
                </div>
            }
        >
            <Head title="Audit log — Control" />

            <div className="flex flex-wrap items-center gap-2">
                <SearchBox filters={filters} />

                <Deferred data="actions" fallback={<span />}>
                    <select
                        value={filters.action ?? ''}
                        onChange={(e) =>
                            pushFilters(ROUTE, {
                                ...filters,
                                action: e.target.value || null,
                            })
                        }
                        className="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Any action</option>
                        {(actions ?? []).map((action) => (
                            <option key={action} value={action}>
                                {action}
                            </option>
                        ))}
                    </select>
                </Deferred>

                <input
                    type="date"
                    value={filters.from ?? ''}
                    onChange={(e) =>
                        pushFilters(ROUTE, { ...filters, from: e.target.value || null })
                    }
                    className="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                    aria-label="From"
                />
                <input
                    type="date"
                    value={filters.to ?? ''}
                    onChange={(e) =>
                        pushFilters(ROUTE, { ...filters, to: e.target.value || null })
                    }
                    className="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                    aria-label="To"
                />

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
                    current={filters.actor}
                    onSelect={(actor) => pushFilters(ROUTE, { ...filters, actor })}
                    tabs={[
                        { key: null, label: 'Everyone', count: tabCounts?.all },
                        {
                            key: 'superadmin',
                            label: 'Admins',
                            count: tabCounts?.superadmin,
                        },
                        { key: 'tenant', label: 'Resellers', count: tabCounts?.tenant },
                        { key: 'system', label: 'System', count: tabCounts?.system },
                    ]}
                />
            </Deferred>

            {entries.data.length === 0 ? (
                <Empty>
                    {isFiltered ? 'Nothing matches these filters.' : 'Nothing recorded yet.'}
                </Empty>
            ) : (
                <ul className="mt-4 space-y-1.5">
                    {entries.data.map((entry) => (
                        <li
                            key={entry.id}
                            className="rounded-lg border border-border bg-card px-4 py-2.5 text-sm"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <ActorChip entry={entry} />

                                <span className="font-mono font-medium">
                                    {entry.action}
                                </span>

                                {entry.tenantId !== null && (
                                    <Link
                                        href={route('admin.tenants.show', entry.tenantId)}
                                        className="text-xs text-primary hover:underline"
                                    >
                                        reseller #{entry.tenantId}
                                    </Link>
                                )}

                                <span className="ml-auto flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                                    {entry.ip && (
                                        <span className="font-mono">{entry.ip}</span>
                                    )}
                                    {dateTime(entry.at)}
                                </span>
                            </div>

                            {entry.details && Object.keys(entry.details).length > 0 && (
                                <Details details={entry.details} />
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <Pagination
                meta={entries.meta}
                onPage={(page) => pushFilters(ROUTE, filters, page)}
            />
        </AdminLayout>
    );
}

function ActorChip({ entry }: { entry: AuditEntry }) {
    const styles: Record<string, string> = {
        superadmin: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        tenant: 'bg-primary/10 text-primary',
        system: 'bg-muted text-muted-foreground',
    };

    return (
        <span
            className={[
                'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                styles[entry.actorType] ?? 'bg-muted text-muted-foreground',
            ].join(' ')}
        >
            {entry.actor ?? entry.actorType}
        </span>
    );
}

/**
 * The recorded detail, folded away by default.
 *
 * Rendered as key/value rather than raw JSON where it can be: an audit line
 * that has to be parsed by eye is one nobody reads.
 */
function Details({ details }: { details: Record<string, unknown> }) {
    const entries = Object.entries(details).filter(([key]) => key !== 'actor');

    if (entries.length === 0) {
        return null;
    }

    return (
        <details className="mt-1.5">
            <summary className="cursor-pointer text-xs text-muted-foreground">
                {entries.length} detail{entries.length === 1 ? '' : 's'}
            </summary>

            <dl className="mt-1.5 grid gap-x-4 gap-y-1 sm:grid-cols-2">
                {entries.map(([key, value]) => (
                    <div key={key} className="flex gap-2 text-xs">
                        <dt className="shrink-0 text-muted-foreground">
                            {key.replace(/_/g, ' ')}
                        </dt>
                        <dd className="min-w-0 break-words font-mono">
                            {typeof value === 'object' && value !== null
                                ? JSON.stringify(value)
                                : String(value)}
                        </dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}

function SearchBox({ filters }: { filters: AuditFilterState }) {
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
        <div className="relative min-w-[14rem] flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search action or IP"
                className="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            />
        </div>
    );
}
