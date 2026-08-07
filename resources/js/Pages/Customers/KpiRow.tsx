import {
    CircleDollarSign,
    Crown,
    LucideIcon,
    TrendingDown,
    TrendingUp,
    UserPlus,
    Users,
    Wallet,
    Zap,
} from 'lucide-react';
import { compact, money } from './bits';
import { CustomersPageProps, Kpi } from './types';

/**
 * The six headline figures.
 *
 * Deliberately reported over the whole book rather than the current filter: a
 * "Total customers" that changed as someone typed in the search box would be
 * a different number every keystroke and mean nothing. The table below is the
 * filtered view; this row is the shop.
 *
 * They arrive deferred, so the row renders its own skeleton first — the tiles
 * keep their exact height, and nothing on the page moves when the numbers land.
 */
type Tile = {
    key: keyof NonNullable<CustomersPageProps['kpis']>;
    label: string;
    icon: LucideIcon;
    format: (value: number) => string;
    /** Money tiles read as money; counts read as counts. */
    tone?: 'money';
};

const TILES: Tile[] = [
    { key: 'total', label: 'Total customers', icon: Users, format: compact },
    { key: 'newToday', label: 'New today', icon: UserPlus, format: compact },
    { key: 'vip', label: 'VIP', icon: Crown, format: compact },
    { key: 'active', label: 'Active (30d)', icon: Zap, format: compact },
    { key: 'wallets', label: 'Wallet balances', icon: Wallet, format: money, tone: 'money' },
    {
        key: 'lifetime',
        label: 'Lifetime revenue',
        icon: CircleDollarSign,
        format: money,
        tone: 'money',
    },
];

export default function KpiRow({ kpis }: { kpis?: CustomersPageProps['kpis'] }) {
    return (
        <section aria-label="Customer totals">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
                {TILES.map((tile) =>
                    kpis ? (
                        <KpiCard key={tile.key} tile={tile} kpi={kpis[tile.key]} />
                    ) : (
                        <KpiSkeleton key={tile.key} />
                    ),
                )}
            </div>
        </section>
    );
}

function KpiCard({ tile, kpi }: { tile: Tile; kpi: Kpi }) {
    const Icon = tile.icon;

    return (
        <div className="group rounded-xl border border-border bg-card p-4 transition-colors hover:border-foreground/15">
            <div className="flex items-start justify-between gap-2">
                <p className="text-xs font-medium text-muted-foreground">{tile.label}</p>
                <Icon
                    className="size-4 shrink-0 text-muted-foreground/60 transition-colors group-hover:text-muted-foreground"
                    aria-hidden
                />
            </div>

            <p className="font-data mt-2 text-2xl font-semibold tabular-nums tracking-tight">
                {tile.format(kpi.value)}
            </p>

            {kpi.delta !== null && <Delta value={kpi.delta} />}
        </div>
    );
}

/**
 * Direction shown as an arrow as well as a colour — the green and red alone
 * would carry no meaning for a red-green colourblind reader.
 */
function Delta({ value }: { value: number }) {
    const rising = value >= 0;
    const Icon = rising ? TrendingUp : TrendingDown;

    return (
        <p
            className={`mt-1 inline-flex items-center gap-1 text-xs font-medium ${
                rising ? 'text-primary' : 'text-destructive'
            }`}
        >
            <Icon className="size-3" aria-hidden />
            {rising ? '+' : ''}
            {value}%
            <span className="font-normal text-muted-foreground">vs before</span>
        </p>
    );
}

function KpiSkeleton() {
    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex items-start justify-between gap-2">
                <div className="h-3 w-20 animate-pulse rounded bg-muted" />
                <div className="size-4 animate-pulse rounded bg-muted" />
            </div>
            <div className="mt-2.5 h-7 w-16 animate-pulse rounded bg-muted" />
            <div className="mt-2 h-3 w-24 animate-pulse rounded bg-muted" />
        </div>
    );
}
