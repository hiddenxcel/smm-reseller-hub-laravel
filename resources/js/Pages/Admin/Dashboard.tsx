import StatTile from '@/components/charts/StatTile';
import TrendChart from '@/components/charts/TrendChart';
import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CreditCard,
    ShoppingBag,
    TrendingUp,
    Users,
} from 'lucide-react';
import {
    PlatformAlerts,
    PlatformKpis,
    RecentSignup,
    TopReseller,
    TrendPoint,
} from './types';

type Props = {
    kpis: PlatformKpis;
    alerts: PlatformAlerts;
    trend?: TrendPoint[];
    serviceMix?: Record<string, number>;
    topResellers?: TopReseller[];
    recentSignups?: RecentSignup[];
};

const SERVICE_LABELS: Record<string, string> = {
    order_bot: 'Order Bot',
    support_bot: 'Support Bot',
    ai_tickets: 'AI Tickets',
    ai_chat: 'AI Chat',
    number_rental: 'Number Rental',
};

export default function AdminDashboard({
    kpis,
    alerts,
    trend,
    serviceMix,
    topResellers,
    recentSignups,
}: Props) {
    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Dashboard</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        The whole platform, across every reseller.
                    </p>
                </div>
            }
        >
            <Head title="Dashboard — Control" />

            <AlertRow alerts={alerts} />

            <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile
                    label="Platform revenue"
                    value={money(kpis.revenue.value)}
                    delta={kpis.revenue.delta}
                    icon={TrendingUp}
                    footer={
                        <p className="text-xs text-muted-foreground">
                            What resellers paid us, last 30 days
                        </p>
                    }
                />
                <StatTile
                    label="New resellers"
                    value={kpis.signups.value.toLocaleString()}
                    delta={kpis.signups.delta}
                    icon={Users}
                    footer={
                        <p className="text-xs text-muted-foreground">
                            {kpis.tenants.total.toLocaleString()} total ·{' '}
                            {kpis.tenants.paying.toLocaleString()} have paid
                        </p>
                    }
                />
                <StatTile
                    label="Active subscriptions"
                    value={kpis.subscriptions.active.toLocaleString()}
                    icon={CreditCard}
                    footer={
                        <p className="text-xs text-muted-foreground">
                            {kpis.subscriptions.sandbox.toLocaleString()} in sandbox
                        </p>
                    }
                />
                <StatTile
                    label="Orders today"
                    value={kpis.ordersToday.toLocaleString()}
                    icon={ShoppingBag}
                    footer={
                        <p className="text-xs text-muted-foreground">
                            Across all resellers' bots
                        </p>
                    }
                />
            </div>

            <div className="mt-6 grid gap-4 lg:grid-cols-3">
                <div className="rounded-xl border border-border bg-card p-5 lg:col-span-2">
                    <h2 className="font-heading font-bold">Revenue</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Subscription payments, last 30 days
                    </p>

                    <Deferred data="trend" fallback={<ChartSkeleton />}>
                        {trend && trend.length > 0 ? (
                            <TrendChart
                                points={trend.map((point) => ({
                                    date: point.date,
                                    value: point.revenue,
                                }))}
                                formatValue={money}
                                label="Revenue"
                            />
                        ) : (
                            <Empty>No payments yet.</Empty>
                        )}
                    </Deferred>
                </div>

                <div className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">What is selling</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Active subscriptions by service
                    </p>

                    <Deferred data="serviceMix" fallback={<ListSkeleton rows={5} />}>
                        <ServiceMix mix={serviceMix ?? {}} />
                    </Deferred>
                </div>
            </div>

            <div className="mt-6 grid gap-4 lg:grid-cols-2">
                <div className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Top resellers</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        By what they have paid us, all time
                    </p>

                    <Deferred data="topResellers" fallback={<ListSkeleton rows={5} />}>
                        {topResellers && topResellers.length > 0 ? (
                            <ul className="space-y-2">
                                {topResellers.map((reseller) => (
                                    <li
                                        key={reseller.tenantId}
                                        className="flex items-center justify-between gap-3 text-sm"
                                    >
                                        <Link
                                            href={route('admin.tenants.show', reseller.tenantId)}
                                            className="min-w-0 flex-1 truncate font-medium hover:underline"
                                        >
                                            {reseller.name}
                                        </Link>
                                        <span className="shrink-0 text-muted-foreground">
                                            {reseller.payments} ×
                                        </span>
                                        <span className="shrink-0 font-semibold tabular-nums">
                                            {money(reseller.revenue)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>Nobody has paid yet.</Empty>
                        )}
                    </Deferred>
                </div>

                <div className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Newest resellers</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Most recent signups
                    </p>

                    <Deferred data="recentSignups" fallback={<ListSkeleton rows={5} />}>
                        {recentSignups && recentSignups.length > 0 ? (
                            <ul className="space-y-2">
                                {recentSignups.map((signup) => (
                                    <li
                                        key={signup.id}
                                        className="flex items-center justify-between gap-3 text-sm"
                                    >
                                        <Link
                                            href={route('admin.tenants.show', signup.id)}
                                            className="min-w-0 flex-1 truncate font-medium hover:underline"
                                        >
                                            {signup.name}
                                        </Link>
                                        {signup.status === 'suspended' && (
                                            <span className="shrink-0 rounded bg-destructive/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-destructive">
                                                Suspended
                                            </span>
                                        )}
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {signup.paid ? 'Paid' : 'Trial'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>No resellers yet.</Empty>
                        )}
                    </Deferred>
                </div>
            </div>
        </AdminLayout>
    );
}

/**
 * Only conditions somebody can act on today.
 *
 * Zero-count alerts are dropped rather than shown greyed out: a row that is
 * always present stops being read, and the whole value of this strip is that
 * something appearing in it means something needs doing.
 */
function AlertRow({ alerts }: { alerts: PlatformAlerts }) {
    const items = [
        {
            label: 'failed payments this week',
            count: alerts.failedPayments,
            tone: 'bad' as const,
        },
        {
            label: 'subscriptions expiring in 7 days',
            count: alerts.expiringSoon,
            tone: 'warn' as const,
        },
        { label: 'open tickets', count: alerts.openTickets, tone: 'warn' as const },
        {
            label: 'suspended resellers',
            count: alerts.suspendedTenants,
            tone: 'warn' as const,
        },
        {
            label: 'numbers silent for 48h',
            count: alerts.silentNumbers,
            tone: 'warn' as const,
        },
    ].filter((item) => item.count > 0);

    if (items.length === 0) {
        return (
            <div className="rounded-xl border border-border bg-card px-4 py-3 text-sm text-muted-foreground">
                Nothing needs attention right now.
            </div>
        );
    }

    return (
        <div className="flex flex-wrap gap-2">
            {items.map((item) => (
                <span
                    key={item.label}
                    className={[
                        'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm',
                        item.tone === 'bad'
                            ? 'border-destructive/30 bg-destructive/10 text-destructive'
                            : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                    ].join(' ')}
                >
                    <AlertTriangle className="size-3.5 shrink-0" aria-hidden />
                    <span className="font-semibold tabular-nums">{item.count}</span>
                    {item.label}
                </span>
            ))}
        </div>
    );
}

function ServiceMix({ mix }: { mix: Record<string, number> }) {
    const entries = Object.entries(mix).sort(([, a], [, b]) => b - a);
    const max = Math.max(...entries.map(([, count]) => count), 1);

    if (entries.length === 0) {
        return <Empty>No active subscriptions.</Empty>;
    }

    return (
        <ul className="space-y-3">
            {entries.map(([key, count]) => (
                <li key={key}>
                    <div className="flex items-center justify-between gap-2 text-sm">
                        <span className="truncate">{SERVICE_LABELS[key] ?? key}</span>
                        <span className="shrink-0 font-semibold tabular-nums">
                            {count.toLocaleString()}
                        </span>
                    </div>
                    <div
                        className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"
                        aria-hidden
                    >
                        <div
                            className="h-full rounded-full bg-[var(--color-chart-1)]"
                            style={{ width: `${(count / max) * 100}%` }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return <p className="py-6 text-center text-sm text-muted-foreground">{children}</p>;
}

function ChartSkeleton() {
    return <div className="h-[220px] animate-pulse rounded-lg bg-muted" />;
}

function ListSkeleton({ rows }: { rows: number }) {
    return (
        <div className="space-y-2">
            {Array.from({ length: rows }).map((_, index) => (
                <div key={index} className="h-5 animate-pulse rounded bg-muted" />
            ))}
        </div>
    );
}

function money(value: number): string {
    return `$${value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}
