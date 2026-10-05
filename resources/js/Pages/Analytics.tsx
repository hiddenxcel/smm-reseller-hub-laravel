import StatusMixBar from '@/components/charts/StatusMixBar';
import TrendChart from '@/components/charts/TrendChart';
import {
    OrderStatus,
    formatCompact,
    formatMoney,
} from '@/components/charts/chart-tokens';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, MessageSquare, Trophy } from 'lucide-react';
import { ReactNode, useState } from 'react';

type Delta = number | null;

type Props = {
    range: number;
    ranges: number[];
    summary: {
        revenue: { value: number; delta: Delta };
        orders: { value: number; delta: Delta };
        averageOrder: number | null;
        completionRate: number | null;
        newCustomers: { value: number; delta: Delta };
        repeatRate: number | null;
        profit: { value: number; margin: number | null } | null;
    };
    trend: Array<{ date: string; revenue: number; orders: number }>;
    statusMix: Record<OrderStatus, number>;
    topServices: Array<{ name: string; orders: number; revenue: number }>;
    timing: {
        weekdays: Array<{ label: string; orders: number }>;
        hours: Array<{ hour: number; orders: number }>;
    };
    gateways: Array<{ gateway: string; payments: number; revenue: number }>;
    topCustomers: Array<{ name: string | null; phone: string; orders: number; spent: number }>;
    messages: { received: number; sent: number };
};

const money = (value: number) => formatMoney(value);

const RANGE_LABELS: Record<number, string> = { 7: '7 days', 30: '30 days', 90: '90 days' };

/**
 * The longer view behind the dashboard.
 *
 * The dashboard answers "how is today going?"; this answers "is the shop
 * growing, when do people buy, and what pays?". The window is a link, not
 * state, so "last quarter" can be bookmarked or sent to someone.
 */
export default function Analytics({
    range,
    ranges,
    summary,
    trend,
    statusMix,
    topServices,
    timing,
    gateways,
    topCustomers,
    messages,
}: Props) {
    const hasOrders = summary.orders.value > 0 || summary.revenue.value > 0;

    return (
        <AuthenticatedLayout>
            <Head title="Analytics" />

            <div className="mx-auto max-w-5xl space-y-4 sm:space-y-6">
                <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="min-w-0">
                        <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                            Analytics
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            How your shop is trending, and what drives it.
                        </p>
                    </div>

                    <nav
                        className="inline-flex rounded-xl border border-border bg-muted/50 p-1 text-sm font-medium"
                        aria-label="Time range"
                    >
                        {ranges.map((days) => (
                            <Link
                                key={days}
                                href={route('analytics', { range: days })}
                                preserveScroll
                                className={`flex-1 rounded-lg px-3.5 py-1.5 text-center transition-colors sm:flex-none ${
                                    days === range
                                        ? 'bg-card shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                                aria-current={days === range ? 'page' : undefined}
                            >
                                {RANGE_LABELS[days] ?? `${days} days`}
                            </Link>
                        ))}
                    </nav>
                </header>

                <Overview summary={summary} />

                {!hasOrders && (
                    <p className="rounded-2xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        No orders or payments in the last {range} days yet. The charts below fill in
                        as customers buy.
                    </p>
                )}

                <Trends trend={trend} range={range} />

                <div className="grid gap-4 sm:gap-6 lg:grid-cols-2">
                    <Panel title="Order outcomes" hint={`Last ${range} days`}>
                        <StatusMixBar counts={statusMix} />
                    </Panel>

                    <Panel title="Top services" hint="By revenue">
                        <BarList
                            empty="Your best sellers will show up here."
                            rows={topServices.map((service) => ({
                                label: service.name,
                                value: service.revenue,
                                display: money(service.revenue),
                                note: `${service.orders} ${service.orders === 1 ? 'order' : 'orders'}`,
                            }))}
                        />
                    </Panel>
                </div>

                <Panel title="When customers order" hint="Orders by day and hour">
                    <Timing timing={timing} />
                </Panel>

                <div className="grid gap-4 sm:gap-6 lg:grid-cols-2">
                    <Panel title="Paid through" hint="Successful payments">
                        <BarList
                            empty="Payments will show up here once customers pay."
                            rows={gateways.map((gateway) => ({
                                label: humanise(gateway.gateway),
                                value: gateway.revenue,
                                display: money(gateway.revenue),
                                note: `${gateway.payments} ${gateway.payments === 1 ? 'payment' : 'payments'}`,
                            }))}
                        />
                    </Panel>

                    <Panel title="Best customers" hint="By spend">
                        <TopCustomers customers={topCustomers} />
                    </Panel>
                </div>

                <section className="flex items-center gap-4 rounded-2xl border border-border bg-card p-4 sm:p-5">
                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-muted">
                        <MessageSquare className="size-5 text-muted-foreground" aria-hidden />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="font-heading font-bold">Bot conversations</p>
                        <p className="text-sm text-muted-foreground">
                            {formatCompact(messages.received)} received ·{' '}
                            {formatCompact(messages.sent)} sent in the last {range} days
                        </p>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function Panel({
    title,
    hint,
    action,
    children,
}: {
    title: string;
    hint?: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="min-w-0 rounded-2xl border border-border bg-card p-4 sm:p-5">
            <div className="mb-4 flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-baseline gap-2">
                    <h2 className="font-heading shrink-0 font-bold">{title}</h2>
                    {hint && <p className="truncate text-xs text-muted-foreground">{hint}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

/** Six headline figures in one card, hairline-divided; profit joins when it is known. */
function Overview({ summary }: { summary: Props['summary'] }) {
    return (
        <section
            aria-label="Key figures"
            className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-border bg-border lg:grid-cols-3"
        >
            <Figure
                label="Revenue"
                value={money(summary.revenue.value)}
                delta={summary.revenue.delta}
            />
            <Figure
                label="Orders"
                value={formatCompact(summary.orders.value)}
                delta={summary.orders.delta}
            />
            <Figure
                label="Average order"
                value={summary.averageOrder === null ? '—' : money(summary.averageOrder)}
            />
            <Figure
                label="Completed"
                value={summary.completionRate === null ? '—' : `${summary.completionRate}%`}
                note="of orders placed"
            />
            <Figure
                label="New customers"
                value={formatCompact(summary.newCustomers.value)}
                delta={summary.newCustomers.delta}
            />
            <Figure
                label="Come back"
                value={summary.repeatRate === null ? '—' : `${summary.repeatRate}%`}
                note="ordered more than once"
            />

            {summary.profit && (
                <div className="col-span-2 bg-card p-4 sm:p-5 lg:col-span-3">
                    <p className="text-xs text-muted-foreground sm:text-sm">You kept</p>
                    <p
                        className={`font-heading mt-1 text-2xl font-extrabold tracking-tight ${
                            summary.profit.value < 0 ? 'text-destructive' : ''
                        }`}
                    >
                        {money(summary.profit.value)}
                        {summary.profit.margin !== null && (
                            <span className="ml-2 text-sm font-medium text-muted-foreground">
                                {summary.profit.margin}% margin
                            </span>
                        )}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        On paid orders with a recorded panel cost.
                    </p>
                </div>
            )}
        </section>
    );
}

function Figure({
    label,
    value,
    delta,
    note,
}: {
    label: string;
    value: string;
    delta?: Delta;
    note?: string;
}) {
    const hasDelta = delta !== null && delta !== undefined;
    const flat = hasDelta && delta === 0;
    const rising = hasDelta && delta! > 0;

    return (
        <div className="bg-card p-4 sm:p-5">
            <p className="text-xs text-muted-foreground sm:text-sm">{label}</p>
            <p className="font-heading mt-1 truncate text-2xl font-extrabold tracking-tight sm:text-3xl">
                {value}
            </p>
            <p className="mt-1 flex min-h-4 items-center gap-1 text-xs">
                {hasDelta ? (
                    <>
                        <span
                            className={`flex items-center gap-0.5 ${
                                flat
                                    ? 'text-muted-foreground'
                                    : rising
                                      ? 'text-[#006300] dark:text-[#0ca30c]'
                                      : 'text-destructive'
                            }`}
                        >
                            {!flat &&
                                (rising ? (
                                    <ArrowUpRight className="size-3.5" />
                                ) : (
                                    <ArrowDownRight className="size-3.5" />
                                ))}
                            {Math.abs(delta!).toFixed(1)}%
                        </span>
                        <span className="text-muted-foreground">vs before</span>
                    </>
                ) : (
                    <span className="text-muted-foreground">{note}</span>
                )}
            </p>
        </div>
    );
}

/** Revenue and orders share one chart with a switch — one card on a phone, not two. */
function Trends({ trend, range }: { trend: Props['trend']; range: number }) {
    const [metric, setMetric] = useState<'revenue' | 'orders'>('revenue');

    const isRevenue = metric === 'revenue';
    const points = trend.map((day) => ({
        date: day.date,
        value: isRevenue ? day.revenue : day.orders,
    }));
    const format = (value: number) =>
        isRevenue ? money(value) : Math.round(value).toLocaleString('en-US');

    return (
        <Panel
            title="Trend"
            hint={`Last ${range} days`}
            action={
                <div className="inline-flex rounded-lg bg-muted p-0.5 text-xs font-medium">
                    {(['revenue', 'orders'] as const).map((key) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setMetric(key)}
                            className={`rounded-md px-2.5 py-1 capitalize transition-colors ${
                                metric === key
                                    ? 'bg-card shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {key}
                        </button>
                    ))}
                </div>
            }
        >
            <TrendChart
                points={points}
                variant={isRevenue ? 'area' : 'column'}
                label={isRevenue ? 'Revenue' : 'Orders'}
                formatValue={format}
            />
        </Panel>
    );
}

/** A label, a bar scaled to the leader, and the figure — for any ranked list. */
function BarList({
    rows,
    empty,
}: {
    rows: Array<{ label: string; value: number; display: string; note?: string }>;
    empty: string;
}) {
    if (rows.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    const top = Math.max(rows[0].value, 1);

    return (
        <ul className="space-y-3">
            {rows.map((row) => (
                <li key={row.label}>
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="min-w-0 truncate">{row.label}</span>
                        <span className="shrink-0 text-muted-foreground [font-variant-numeric:tabular-nums]">
                            {row.display}
                            {row.note && <span className="ml-2 text-xs">{row.note}</span>}
                        </span>
                    </div>
                    <div className="mt-1.5 h-1.5 w-full rounded-full bg-muted">
                        <div
                            className="h-full rounded-full"
                            style={{
                                width: `${(row.value / top) * 100}%`,
                                background: 'var(--color-chart-1)',
                            }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function Timing({ timing }: { timing: Props['timing'] }) {
    const busiestDay = [...timing.weekdays].sort((a, b) => b.orders - a.orders)[0];
    const busiestHour = [...timing.hours].sort((a, b) => b.orders - a.orders)[0];
    const any = (busiestDay?.orders ?? 0) > 0;

    if (!any) {
        return (
            <p className="text-sm text-muted-foreground">
                Once orders come in, you will see which days and hours are busiest.
            </p>
        );
    }

    return (
        <div className="space-y-6">
            <p className="text-sm text-muted-foreground">
                Busiest on{' '}
                <span className="font-semibold text-foreground">{busiestDay.label}</span>
                {busiestHour.orders > 0 && (
                    <>
                        , around{' '}
                        <span className="font-semibold text-foreground">
                            {hourLabel(busiestHour.hour)}
                        </span>
                    </>
                )}
                .
            </p>

            <div>
                <h3 className="mb-2 text-xs font-medium text-muted-foreground">By day</h3>
                <Columns
                    values={timing.weekdays.map((day) => ({ key: day.label, value: day.orders }))}
                    labelEvery={1}
                    height="h-24"
                />
            </div>

            <div>
                <h3 className="mb-2 text-xs font-medium text-muted-foreground">By hour</h3>
                <Columns
                    values={timing.hours.map((hour) => ({
                        key: String(hour.hour),
                        value: hour.orders,
                    }))}
                    labelEvery={6}
                    height="h-20"
                    tight
                />
            </div>
        </div>
    );
}

/** Small column chart: the tallest column is the accent, the rest are quiet. */
function Columns({
    values,
    labelEvery,
    height,
    tight = false,
}: {
    values: Array<{ key: string; value: number }>;
    labelEvery: number;
    height: string;
    tight?: boolean;
}) {
    const max = Math.max(...values.map((item) => item.value), 1);

    return (
        <div>
            <div className={`flex items-end ${tight ? 'gap-0.5' : 'gap-2'} ${height}`}>
                {values.map((item) => (
                    <div
                        key={item.key}
                        className="flex h-full min-w-0 flex-1 items-end"
                        title={`${item.key}: ${item.value} ${item.value === 1 ? 'order' : 'orders'}`}
                    >
                        <div
                            className="w-full rounded-t-md"
                            style={{
                                height: `${Math.max((item.value / max) * 100, item.value > 0 ? 6 : 0)}%`,
                                background:
                                    item.value === max
                                        ? 'var(--color-chart-1)'
                                        : 'color-mix(in oklab, var(--color-chart-1) 35%, transparent)',
                            }}
                        />
                    </div>
                ))}
            </div>

            <div className={`mt-1.5 flex ${tight ? 'gap-0.5' : 'gap-2'}`}>
                {values.map((item, index) => (
                    <span
                        key={item.key}
                        className="min-w-0 flex-1 text-center text-[10px] text-muted-foreground"
                    >
                        {index % labelEvery === 0 ? (tight ? hourLabel(Number(item.key)) : item.key) : ''}
                    </span>
                ))}
            </div>
        </div>
    );
}

function TopCustomers({ customers }: { customers: Props['topCustomers'] }) {
    if (customers.length === 0) {
        return <p className="text-sm text-muted-foreground">Your best customers will show up here.</p>;
    }

    return (
        <ol className="-my-2 divide-y divide-border/60">
            {customers.map((customer, index) => (
                <li key={customer.phone} className="flex items-center gap-3 py-3">
                    <span className="grid size-7 shrink-0 place-items-center rounded-full bg-muted text-xs font-bold text-muted-foreground">
                        {index === 0 ? <Trophy className="size-3.5" aria-hidden /> : index + 1}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">
                            {customer.name ?? customer.phone}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {customer.orders} {customer.orders === 1 ? 'order' : 'orders'}
                            {customer.name && ` · ${customer.phone}`}
                        </p>
                    </div>
                    <span className="shrink-0 text-sm font-semibold [font-variant-numeric:tabular-nums]">
                        {money(customer.spent)}
                    </span>
                </li>
            ))}
        </ol>
    );
}

/** snippe_ke → "Snippe ke": the code is for the machine; this is close enough to read. */
function humanise(code: string): string {
    const text = code.split('_').join(' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function hourLabel(hour: number): string {
    const suffix = hour < 12 ? 'am' : 'pm';
    const twelve = hour % 12 === 0 ? 12 : hour % 12;

    return `${twelve}${suffix}`;
}
