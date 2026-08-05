import StatTile from '@/components/charts/StatTile';
import TrendChart from '@/components/charts/TrendChart';
import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link } from '@inertiajs/react';
import { AlertTriangle, Repeat, TrendingUp, Users } from 'lucide-react';
import { Empty, money } from '../bits';
import {
    ConversionRow,
    GatewayRow,
    GrowthMetric,
    MonthlyRow,
    Mrr,
    TopReseller,
} from '../types';

type Props = {
    mrr: Mrr;
    growth: { revenue: GrowthMetric; signups: GrowthMetric };
    monthly?: MonthlyRow[];
    conversion?: ConversionRow[];
    byGateway?: GatewayRow[];
    topResellers?: TopReseller[];
    serviceMix?: Array<{ service: string; active: number }>;
};

const SERVICE_LABELS: Record<string, string> = {
    order_bot: 'Order Bot',
    support_bot: 'Support Bot',
    ai_tickets: 'AI Tickets',
    ai_chat: 'AI Chat',
    number_rental: 'Number Rental',
};

export default function ReportsIndex({
    mrr,
    growth,
    monthly,
    conversion,
    byGateway,
    topResellers,
    serviceMix,
}: Props) {
    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Reports</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        The business, month by month. Revenue means what resellers
                        paid us.
                    </p>
                </div>
            }
        >
            <Head title="Reports — Control" />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatTile
                    label="MRR"
                    value={money(mrr.total)}
                    icon={Repeat}
                    footer={
                        <p className="text-xs text-muted-foreground">
                            From live subscriptions at plan prices
                        </p>
                    }
                />
                <StatTile
                    label="Revenue (30 days)"
                    value={money(growth.revenue.value)}
                    delta={growth.revenue.delta}
                    icon={TrendingUp}
                />
                <StatTile
                    label="New resellers (30 days)"
                    value={growth.signups.value.toLocaleString()}
                    delta={growth.signups.delta}
                    icon={Users}
                />
            </div>

            {/* A live subscription with no plan attached contributes nothing to
                MRR. Surfaced rather than rounded away — it is a data gap. */}
            {mrr.unpriced > 0 && (
                <p className="mt-4 flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <span>
                        {mrr.unpriced} live subscription
                        {mrr.unpriced === 1 ? ' has' : 's have'} no plan attached, so
                        {mrr.unpriced === 1 ? ' it is' : ' they are'} missing from MRR.
                    </span>
                </p>
            )}

            <section className="mt-6 rounded-xl border border-border bg-card p-5">
                <h2 className="font-heading font-bold">Revenue by month</h2>
                <p className="mb-4 text-sm text-muted-foreground">
                    Successful subscription payments, last 12 months
                </p>

                <Deferred
                    data="monthly"
                    fallback={<div className="h-[220px] animate-pulse rounded-lg bg-muted" />}
                >
                    {monthly && monthly.some((row) => row.revenue > 0) ? (
                        <>
                            <TrendChart
                                points={monthly.map((row) => ({
                                    date: row.month,
                                    value: row.revenue,
                                }))}
                                variant="column"
                                formatValue={money}
                                label="Revenue"
                            />

                            <div className="mt-4 overflow-x-auto">
                                <table className="w-full min-w-[32rem] text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-left text-xs uppercase tracking-wider text-muted-foreground">
                                            <th className="px-2 py-2 font-semibold">Month</th>
                                            <th className="px-2 py-2 text-right font-semibold">
                                                Revenue
                                            </th>
                                            <th className="px-2 py-2 text-right font-semibold">
                                                Signups
                                            </th>
                                            <th className="px-2 py-2 text-right font-semibold">
                                                Paying
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {[...monthly].reverse().map((row) => (
                                            <tr
                                                key={row.month}
                                                className="border-b border-border last:border-0"
                                            >
                                                <td className="px-2 py-2">{row.label}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {money(row.revenue)}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums text-muted-foreground">
                                                    {row.signups}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums text-muted-foreground">
                                                    {row.payingResellers}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    ) : (
                        <Empty>No revenue recorded yet.</Empty>
                    )}
                </Deferred>
            </section>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Signup to paid</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        How many of each month's signups have ever paid
                    </p>

                    <Deferred data="conversion" fallback={<ListSkeleton />}>
                        {conversion && conversion.length > 0 ? (
                            <ul className="space-y-3">
                                {[...conversion].reverse().map((row) => (
                                    <li key={row.month}>
                                        <div className="flex items-center justify-between gap-2 text-sm">
                                            <span>{row.label}</span>
                                            <span className="tabular-nums text-muted-foreground">
                                                {row.paid}/{row.signups}
                                                {row.rate !== null && (
                                                    <span className="ml-1.5 font-semibold text-foreground">
                                                        {row.rate}%
                                                    </span>
                                                )}
                                            </span>
                                        </div>
                                        <div
                                            className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"
                                            aria-hidden
                                        >
                                            <div
                                                className="h-full rounded-full bg-[var(--color-chart-1)]"
                                                style={{ width: `${row.rate ?? 0}%` }}
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>No signups yet.</Empty>
                        )}
                    </Deferred>
                </section>

                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">MRR by service</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Which services carry the business
                    </p>

                    <Deferred data="serviceMix" fallback={<ListSkeleton />}>
                        {Object.keys(mrr.byService).length > 0 ? (
                            <ul className="space-y-2 text-sm">
                                {Object.entries(mrr.byService)
                                    .sort(([, a], [, b]) => b.mrr - a.mrr)
                                    .map(([service, value]) => (
                                        <li
                                            key={service}
                                            className="flex items-center justify-between gap-3"
                                        >
                                            <span className="truncate">
                                                {SERVICE_LABELS[service] ?? service}
                                            </span>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                {value.subscriptions} sub
                                                {value.subscriptions === 1 ? '' : 's'}
                                            </span>
                                            <span className="shrink-0 font-semibold tabular-nums">
                                                {money(value.mrr)}
                                            </span>
                                        </li>
                                    ))}
                            </ul>
                        ) : (
                            <Empty>No priced subscriptions.</Empty>
                        )}

                        {serviceMix && (
                            <p className="mt-4 border-t border-border pt-3 text-xs text-muted-foreground">
                                {serviceMix
                                    .filter((row) => row.active > 0)
                                    .map(
                                        (row) =>
                                            `${SERVICE_LABELS[row.service] ?? row.service}: ${row.active}`,
                                    )
                                    .join(' · ') || 'No active subscriptions.'}
                            </p>
                        )}
                    </Deferred>
                </section>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Revenue by gateway</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        All time, successful payments only
                    </p>

                    <Deferred data="byGateway" fallback={<ListSkeleton />}>
                        {byGateway && byGateway.length > 0 ? (
                            <ul className="space-y-2 text-sm">
                                {byGateway.map((row) => (
                                    <li
                                        key={row.gateway}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span className="truncate">{row.gateway}</span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {row.payments} payment
                                            {row.payments === 1 ? '' : 's'}
                                        </span>
                                        <span className="shrink-0 font-semibold tabular-nums">
                                            {money(row.revenue)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>No payments yet.</Empty>
                        )}
                    </Deferred>
                </section>

                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Top resellers</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        By lifetime spend with us
                    </p>

                    <Deferred data="topResellers" fallback={<ListSkeleton />}>
                        {topResellers && topResellers.length > 0 ? (
                            <ul className="space-y-2 text-sm">
                                {topResellers.map((row) => (
                                    <li
                                        key={row.tenantId}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <Link
                                            href={route('admin.tenants.show', row.tenantId)}
                                            className="min-w-0 flex-1 truncate font-medium hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        <span className="shrink-0 font-semibold tabular-nums">
                                            {money(row.revenue)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>Nobody has paid yet.</Empty>
                        )}
                    </Deferred>
                </section>
            </div>
        </AdminLayout>
    );
}

function ListSkeleton() {
    return (
        <div className="space-y-2">
            {[0, 1, 2, 3].map((i) => (
                <div key={i} className="h-6 animate-pulse rounded bg-muted" />
            ))}
        </div>
    );
}
