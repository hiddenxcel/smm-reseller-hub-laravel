import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { Empty, money, shortDate } from '../bits';
import {
    AutoPausedRow,
    CatalogueKpis,
    CatalogueSizeRow,
    PanelRow,
    PlatformRow,
    TopSellingRow,
} from '../types';

type Props = {
    kpis: CatalogueKpis;
    platforms?: PlatformRow[];
    panels?: PanelRow[];
    autoPaused?: AutoPausedRow[];
    topSelling?: TopSellingRow[];
    biggestCatalogues?: CatalogueSizeRow[];
    pricing?: { avgMarkup: number | null; priced: number };
};

export default function CatalogueIndex({
    kpis,
    platforms,
    panels,
    autoPaused,
    topSelling,
    biggestCatalogues,
    pricing,
}: Props) {
    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Services</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        What resellers are selling. Read-only — each catalogue
                        belongs to the reseller who priced it.
                    </p>
                </div>
            }
        >
            <Head title="Services — Control" />

            <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
                <Tile label="Services" value={kpis.services} />
                <Tile label="Active" value={kpis.active} />
                <Tile
                    label="Auto-paused"
                    value={kpis.autoPaused}
                    alert={kpis.autoPaused > 0}
                />
                <Tile label="Panels" value={kpis.panels} />
                <Tile label="Resellers selling" value={kpis.sellingTenants} />
            </div>

            {/* Auto-paused first: a cluster of these is usually one broken
                provider affecting many resellers, which is ours to notice. */}
            <section className="mt-6 rounded-xl border border-border bg-card p-5">
                <h2 className="font-heading font-bold">Auto-paused services</h2>
                <p className="mb-4 text-sm text-muted-foreground">
                    Paused by the platform after their panel kept failing. Several
                    at once usually means one upstream provider is down.
                </p>

                <Deferred data="autoPaused" fallback={<ListSkeleton />}>
                    {autoPaused && autoPaused.length > 0 ? (
                        <Table headers={['Reseller', 'Service', 'Platform', 'Synced']}>
                            {autoPaused.map((row) => (
                                <tr key={row.id} className="border-b border-border last:border-0">
                                    <Td>
                                        <Link
                                            href={route('admin.tenants.show', row.tenantId)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.tenant}
                                        </Link>
                                    </Td>
                                    <Td>{row.name ?? '—'}</Td>
                                    <Td muted>{row.platform ?? '—'}</Td>
                                    <Td muted>{shortDate(row.syncedAt)}</Td>
                                </tr>
                            ))}
                        </Table>
                    ) : (
                        <Empty>Nothing is auto-paused.</Empty>
                    )}
                </Deferred>
            </section>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Panels in use</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Grouped by URL — resellers name the same provider
                        differently. Many resellers behind an unhealthy panel is a
                        platform-wide problem.
                    </p>

                    <Deferred data="panels" fallback={<ListSkeleton />}>
                        {panels && panels.length > 0 ? (
                            <ul className="space-y-2 text-sm">
                                {panels.map((panel) => (
                                    <li
                                        key={panel.url}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span className="min-w-0 flex-1 truncate font-mono text-xs">
                                            {panel.host}
                                        </span>
                                        {panel.unhealthy > 0 && (
                                            <span className="flex shrink-0 items-center gap-1 text-xs text-destructive">
                                                <AlertTriangle className="size-3" />
                                                {panel.unhealthy}
                                            </span>
                                        )}
                                        <span className="shrink-0 tabular-nums text-muted-foreground">
                                            {panel.tenants} reseller
                                            {panel.tenants === 1 ? '' : 's'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>No panels connected.</Empty>
                        )}
                    </Deferred>
                </section>

                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Platforms</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        What resellers stock, by number of services
                    </p>

                    <Deferred data="platforms" fallback={<ListSkeleton />}>
                        <BarList
                            rows={(platforms ?? []).map((row) => ({
                                label: row.platform,
                                value: row.services,
                                hint: `${row.tenants} reseller${row.tenants === 1 ? '' : 's'}`,
                            }))}
                            empty="No services yet."
                        />
                    </Deferred>
                </section>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Selling most</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        By orders, last 30 days. The value is their customers'
                        money, not ours.
                    </p>

                    <Deferred data="topSelling" fallback={<ListSkeleton />}>
                        {topSelling && topSelling.length > 0 ? (
                            <ul className="space-y-2 text-sm">
                                {topSelling.map((row) => (
                                    <li
                                        key={row.name}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span className="min-w-0 flex-1 truncate">
                                            {row.name}
                                        </span>
                                        <span className="shrink-0 tabular-nums text-muted-foreground">
                                            {row.orders.toLocaleString()}
                                        </span>
                                        <span className="shrink-0 tabular-nums">
                                            {money(row.revenue)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>No orders in this window.</Empty>
                        )}
                    </Deferred>
                </section>

                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Biggest catalogues</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Usually the most invested resellers
                    </p>

                    <Deferred data="biggestCatalogues" fallback={<ListSkeleton />}>
                        {biggestCatalogues && biggestCatalogues.length > 0 ? (
                            <ul className="space-y-2 text-sm">
                                {biggestCatalogues.map((row) => (
                                    <li
                                        key={row.tenantId}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <Link
                                            href={route('admin.tenants.show', row.tenantId)}
                                            className="min-w-0 flex-1 truncate font-medium hover:underline"
                                        >
                                            {row.tenant}
                                        </Link>
                                        <span className="shrink-0 tabular-nums text-muted-foreground">
                                            {row.services.toLocaleString()}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>No catalogues yet.</Empty>
                        )}
                    </Deferred>

                    <Deferred data="pricing" fallback={<span />}>
                        {pricing?.avgMarkup !== null && pricing !== undefined && (
                            <p className="mt-4 border-t border-border pt-3 text-xs text-muted-foreground">
                                Average markup across {pricing.priced.toLocaleString()}{' '}
                                priced services:{' '}
                                <span className="font-semibold text-foreground">
                                    {pricing.avgMarkup}×
                                </span>
                            </p>
                        )}
                    </Deferred>
                </section>
            </div>
        </AdminLayout>
    );
}

function Tile({
    label,
    value,
    alert = false,
}: {
    label: string;
    value: number;
    alert?: boolean;
}) {
    return (
        <div
            className={[
                'rounded-xl border bg-card p-4',
                alert ? 'border-amber-500/40' : 'border-border',
            ].join(' ')}
        >
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={[
                    'font-heading mt-1 text-xl font-extrabold tabular-nums',
                    alert ? 'text-amber-700 dark:text-amber-300' : '',
                ].join(' ')}
            >
                {value.toLocaleString()}
            </p>
        </div>
    );
}

function BarList({
    rows,
    empty,
}: {
    rows: Array<{ label: string; value: number; hint?: string }>;
    empty: string;
}) {
    if (rows.length === 0) {
        return <Empty>{empty}</Empty>;
    }

    const max = Math.max(...rows.map((row) => row.value), 1);

    return (
        <ul className="space-y-3">
            {rows.map((row) => (
                <li key={row.label}>
                    <div className="flex items-center justify-between gap-2 text-sm">
                        <span className="truncate">{row.label}</span>
                        <span className="shrink-0 tabular-nums text-muted-foreground">
                            {row.hint ? `${row.hint} · ` : ''}
                            <span className="font-semibold text-foreground">
                                {row.value.toLocaleString()}
                            </span>
                        </span>
                    </div>
                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden>
                        <div
                            className="h-full rounded-full bg-[var(--color-chart-1)]"
                            style={{ width: `${(row.value / max) * 100}%` }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function Table({
    headers,
    children,
}: {
    headers: string[];
    children: React.ReactNode;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[36rem] text-sm">
                <thead>
                    <tr className="border-b border-border text-left text-xs uppercase tracking-wider text-muted-foreground">
                        {headers.map((header) => (
                            <th key={header} className="px-2 py-2 font-semibold">
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

function Td({
    children,
    muted = false,
}: {
    children: React.ReactNode;
    muted?: boolean;
}) {
    return (
        <td className={['px-2 py-2', muted ? 'text-muted-foreground' : ''].join(' ')}>
            {children}
        </td>
    );
}

function ListSkeleton() {
    return (
        <div className="space-y-2">
            {[0, 1, 2].map((i) => (
                <div key={i} className="h-6 animate-pulse rounded bg-muted" />
            ))}
        </div>
    );
}
