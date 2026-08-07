import {
    CircleDot,
    Layers,
    LucideIcon,
    Percent,
    Package,
    RefreshCw,
    TriangleAlert,
} from 'lucide-react';
import { compact, price, relativeTime } from './bits';
import { Kpis } from './types';

/**
 * The headline figures for the catalogue.
 *
 * Five are counts and one is a warning. `underwater` was not asked for, but it
 * is the only number here that costs a reseller money while they are not
 * looking — every order on those services loses them the difference — so it
 * takes a tile and turns red when it is above zero.
 */
export default function KpiRow({
    kpis,
    onFilterUnderwater,
}: {
    kpis?: Kpis;
    onFilterUnderwater: () => void;
}) {
    if (!kpis) {
        return (
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
                {Array.from({ length: 6 }).map((_, index) => (
                    <Skeleton key={index} />
                ))}
            </div>
        );
    }

    return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
            <Tile label="Services" value={compact(kpis.total)} icon={Package} />
            <Tile
                label="Active"
                value={compact(kpis.active)}
                icon={CircleDot}
                sub={`${compact(kpis.hidden)} hidden · ${compact(kpis.paused)} paused`}
            />
            <Tile label="Platforms" value={compact(kpis.platforms)} icon={Layers} />
            <Tile
                label="Avg profit"
                value={kpis.avgProfit === null ? '—' : price(kpis.avgProfit)}
                icon={Percent}
                sub={kpis.avgProfit === null ? 'No costs known' : 'per 1,000'}
            />
            <Tile
                label="Avg margin"
                value={kpis.avgMargin === null ? '—' : `${kpis.avgMargin.toFixed(1)}%`}
                icon={Percent}
            />

            {kpis.underwater > 0 ? (
                <button
                    type="button"
                    onClick={onFilterUnderwater}
                    className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-left transition-colors hover:border-destructive/50"
                >
                    <div className="flex items-start justify-between gap-2">
                        <p className="text-xs font-medium text-destructive">Losing money</p>
                        <TriangleAlert className="size-4 shrink-0 text-destructive" aria-hidden />
                    </div>
                    <p className="font-data mt-2 text-2xl font-semibold tabular-nums tracking-tight text-destructive">
                        {compact(kpis.underwater)}
                    </p>
                    <p className="mt-1 text-xs text-destructive/80">Priced below cost — review</p>
                </button>
            ) : (
                <Tile
                    label="Last sync"
                    value={kpis.lastSyncedAt ? relativeTime(kpis.lastSyncedAt) : 'Never'}
                    icon={RefreshCw}
                    sub={kpis.lastSyncedAt ? undefined : 'Sync to refresh costs'}
                />
            )}
        </div>
    );
}

function Tile({
    label,
    value,
    icon: Icon,
    sub,
}: {
    label: string;
    value: string;
    icon: LucideIcon;
    sub?: string;
}) {
    return (
        <div className="group rounded-xl border border-border bg-card p-4 transition-colors hover:border-foreground/15">
            <div className="flex items-start justify-between gap-2">
                <p className="text-xs font-medium text-muted-foreground">{label}</p>
                <Icon
                    className="size-4 shrink-0 text-muted-foreground/60 transition-colors group-hover:text-muted-foreground"
                    aria-hidden
                />
            </div>
            <p className="font-data mt-2 truncate text-2xl font-semibold tabular-nums tracking-tight">
                {value}
            </p>
            {sub && <p className="mt-1 truncate text-xs text-muted-foreground">{sub}</p>}
        </div>
    );
}

function Skeleton() {
    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex items-start justify-between gap-2">
                <div className="h-3 w-16 animate-pulse rounded bg-muted" />
                <div className="size-4 animate-pulse rounded bg-muted" />
            </div>
            <div className="mt-2.5 h-7 w-14 animate-pulse rounded bg-muted" />
            <div className="mt-2 h-3 w-20 animate-pulse rounded bg-muted" />
        </div>
    );
}
