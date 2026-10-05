import {
    CircleDollarSign,
    LucideIcon,
    TrendingDown,
    TrendingUp,
    UserPlus,
    Users,
    Wallet,
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

// VIP and Active are left out: the tabs under this row carry the same counts,
// and six tiles on a phone is a screen of numbers before the first customer.
const TILES: Tile[] = [
    { key: 'total', label: 'Customers', icon: Users, format: compact },
    { key: 'newToday', label: 'New today', icon: UserPlus, format: compact },
    { key: 'wallets', label: 'In wallets', icon: Wallet, format: money, tone: 'money' },
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
        <section
            aria-label="Customer totals"
            className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-border bg-border lg:grid-cols-4"
        >
            {TILES.map((tile) =>
                kpis ? (
                    <KpiCard key={tile.key} tile={tile} kpi={kpis[tile.key]} />
                ) : (
                    <KpiSkeleton key={tile.key} />
                ),
            )}
        </section>
    );
}

function KpiCard({ tile, kpi }: { tile: Tile; kpi: Kpi }) {
    return (
        <div className="bg-card p-4">
            <p className="text-xs text-muted-foreground sm:text-sm">{tile.label}</p>

            <p className="font-heading mt-1 truncate text-2xl font-extrabold tracking-tight">
                {tile.format(kpi.value)}
            </p>

            <div className="mt-1 min-h-4">{kpi.delta !== null && <Delta value={kpi.delta} />}</div>
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
            <span className="hidden font-normal text-muted-foreground sm:inline">vs before</span>
        </p>
    );
}

function KpiSkeleton() {
    return (
        <div className="bg-card p-4">
            <div className="h-3 w-20 animate-pulse rounded bg-muted" />
            <div className="mt-2.5 h-7 w-16 animate-pulse rounded bg-muted" />
            <div className="mt-2 h-3 w-24 animate-pulse rounded bg-muted" />
        </div>
    );
}
