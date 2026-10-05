import { TriangleAlert } from 'lucide-react';
import { compact, price, relativeTime } from './bits';
import { Kpis } from './types';

/**
 * The headline figures for the catalogue, in one card.
 *
 * The fourth cell is the only warning on the page. `underwater` was not asked
 * for, but it is the only number here that costs a reseller money while they
 * are not looking — every order on those services loses them the difference —
 * so it turns red when above zero, and is a button that filters to them.
 *
 * Active and Platforms are left out: the tabs and the platform list already
 * carry those counts.
 */
export default function KpiRow({
    kpis,
    onFilterUnderwater,
}: {
    kpis?: Kpis;
    onFilterUnderwater: () => void;
}) {
    const shell =
        'grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-border bg-border lg:grid-cols-4';

    if (!kpis) {
        return (
            <div className={shell}>
                {Array.from({ length: 4 }).map((_, index) => (
                    <Skeleton key={index} />
                ))}
            </div>
        );
    }

    return (
        <div className={shell}>
            <Tile label="Services" value={compact(kpis.total)} sub={`${compact(kpis.active)} active`} />
            <Tile
                label="Avg profit"
                value={kpis.avgProfit === null ? '—' : price(kpis.avgProfit)}
                sub={kpis.avgProfit === null ? 'No costs known' : 'per 1,000'}
            />
            <Tile
                label="Avg margin"
                value={kpis.avgMargin === null ? '—' : `${kpis.avgMargin.toFixed(1)}%`}
            />

            {kpis.underwater > 0 ? (
                <button
                    type="button"
                    onClick={onFilterUnderwater}
                    className="bg-card text-left"
                >
                    <span className="block h-full bg-destructive/5 p-4 transition-colors hover:bg-destructive/10">
                    <p className="flex items-center gap-1.5 text-xs text-destructive sm:text-sm">
                        <TriangleAlert className="size-3.5 shrink-0" aria-hidden />
                        Losing money
                    </p>
                    <p className="font-heading mt-1 text-2xl font-extrabold tracking-tight text-destructive">
                        {compact(kpis.underwater)}
                    </p>
                    <p className="mt-1 truncate text-xs text-destructive/80">Priced below cost</p>
                    </span>
                </button>
            ) : (
                <Tile
                    label="Last sync"
                    value={kpis.lastSyncedAt ? relativeTime(kpis.lastSyncedAt) : 'Never'}
                    sub={kpis.lastSyncedAt ? undefined : 'Sync to refresh costs'}
                />
            )}
        </div>
    );
}

function Tile({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="bg-card p-4">
            <p className="text-xs text-muted-foreground sm:text-sm">{label}</p>
            <p className="font-heading mt-1 truncate text-2xl font-extrabold tracking-tight">
                {value}
            </p>
            <p className="mt-1 min-h-4 truncate text-xs text-muted-foreground">{sub}</p>
        </div>
    );
}

function Skeleton() {
    return (
        <div className="bg-card p-4">
            <div className="h-3 w-16 animate-pulse rounded bg-muted" />
            <div className="mt-2.5 h-7 w-14 animate-pulse rounded bg-muted" />
            <div className="mt-2 h-3 w-20 animate-pulse rounded bg-muted" />
        </div>
    );
}
