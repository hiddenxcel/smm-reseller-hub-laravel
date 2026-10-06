import StatusMixBar from '@/components/charts/StatusMixBar';
import TrendChart from '@/components/charts/TrendChart';
import {
    OrderStatus,
    STATUS_COLORS,
    STATUS_LABELS,
    formatCompact,
    formatMoney,
} from '@/components/charts/chart-tokens';
import AnnouncementBanner from '@/components/AnnouncementBanner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    CheckCircle2,
    ChevronDown,
    Clock,
    LifeBuoy,
    LucideIcon,
    Package,
    ShoppingBag,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import { ReactNode, useState } from 'react';

type Kpi = {
    value: number;
    previous: number;
    delta: number | null;
    total?: number;
};

type BotState = 'online' | 'idle' | 'never_replied' | 'not_connected';

type BotHealth = {
    numbers: Array<{ id: number; display: string }>;
    numbersConnected: number;
    lastReplyAt: string | null;
    state: BotState;
    messagesToday: number;
};

type Props = {
    businessName: string;
    kpis: {
        revenue: Kpi;
        orders: Kpi;
        customers: Kpi;
        walletsHeld: number;
    };
    botStatus: {
        order: BotHealth;
        support: BotHealth;
        numbersConnected: number;
        messagesToday: number;
    };
    trend: Array<{ date: string; revenue: number; orders: number }>;
    statusMix: Record<OrderStatus, number>;
    topServices: Array<{ name: string; orders: number; revenue: number }>;
    profit: {
        summary: {
            revenue: number;
            cost: number;
            profit: number;
            margin: number | null;
            previousProfit: number;
            delta: number | null;
            measuredOrders: number;
            totalOrders: number;
            coverage: number | null;
        };
        byService: Array<{
            name: string;
            orders: number;
            revenue: number;
            cost: number;
            profit: number;
            margin: number | null;
        }>;
        underwater: Array<{
            id: number;
            name: string;
            platform: string;
            myPrice: number;
            costPrice: number;
            lossPerThousand: number;
        }>;
    };
    panels: Array<{
        id: number;
        name: string;
        balance: number | null;
        currency: string | null;
        checkedAt: string | null;
        status: string;
        lowBalance: boolean;
    }>;
    recentOrders: Array<{
        id: number;
        service: string | null;
        customer: string;
        quantity: number | null;
        amount: number | null;
        status: OrderStatus;
        rawStatus: string | null;
        at: string | null;
    }>;
    recentTickets: Array<{
        id: number;
        subject: string | null;
        customer: string;
        status: string;
        priority: string;
        category: string;
        at: string | null;
    }>;
    openTickets: number;
    setup: {
        steps: Array<{
            key: string;
            title: string;
            complete: boolean;
            required: boolean;
            /** Put off for later — still counts as outstanding, not done. */
            skipped: boolean;
        }>;
        completed: number;
        readyToGoLive: boolean;
    };
};

const BOT_STATE: Record<
    BotState,
    { label: string; hint: string; color: string; icon: LucideIcon }
> = {
    online: {
        label: 'online',
        hint: 'Answered within the last 24 hours',
        color: STATUS_COLORS.completed,
        icon: CheckCircle2,
    },
    idle: {
        label: 'quiet',
        hint: 'Connected, but has not replied today',
        color: STATUS_COLORS.pending,
        icon: Clock,
    },
    never_replied: {
        label: 'never replied',
        hint: 'Connected, but has not answered anyone yet',
        color: STATUS_COLORS.pending,
        icon: TriangleAlert,
    },
    not_connected: {
        label: 'no number',
        hint: 'No number is running this bot',
        color: STATUS_COLORS.failed,
        icon: XCircle,
    },
};

const money = (value: number) => formatMoney(value);

/**
 * The dashboard, kept to what a reseller reads in ten seconds: is the shop
 * healthy, how is money moving, and what needs attention. Everything else is
 * one tap further down, not another card.
 *
 * Mobile is the baseline. Related figures share a card instead of each getting
 * their own, so a phone scrolls past four or five blocks rather than fourteen.
 */
export default function Dashboard({
    businessName,
    kpis,
    botStatus,
    trend,
    statusMix,
    topServices,
    profit,
    panels,
    recentOrders,
    recentTickets,
    openTickets,
    setup,
}: Props) {
    // The setup steps are the owner's to finish; a team member would only be
    // sent to pages their role cannot open.
    const isMember = usePage().props.auth.member !== null;
    const setupIncomplete = isMember ? [] : setup.steps.filter((step) => !step.complete);
    const showProfit = profit.summary.measuredOrders > 0 || profit.underwater.length > 0;

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="space-y-4 sm:space-y-6">
                {/* Platform notices, above everything: a maintenance window is
                    worth reading before the numbers underneath it. */}
                <AnnouncementBanner />

                <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="min-w-0">
                        <h1 className="font-heading truncate text-xl font-extrabold tracking-tight sm:text-2xl">
                            {businessName}
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            How your shop has been doing
                        </p>
                    </div>

                    {/* Two bots, sold separately and often on separate
                        numbers — one combined light would call both healthy
                        when only one was. */}
                    <div className="flex flex-wrap gap-2">
                        <BotChip name="Order bot" icon={ShoppingBag} health={botStatus.order} />
                        <BotChip name="Support bot" icon={LifeBuoy} health={botStatus.support} />
                    </div>
                </header>

                {/* Setup stays visible after go-live: payments is optional, so a
                    reseller can be live with something still undone. */}
                {setupIncomplete.length > 0 && <SetupBanner setup={setup} incomplete={setupIncomplete} />}

                <Overview kpis={kpis} />

                {/* Side by side from a laptop up: the chart keeps a sensible
                    height instead of stretching to the full width of a
                    monitor, and the 30-day summary sits where the eye lands
                    next. */}
                <div className="grid grid-cols-1 gap-4 sm:gap-6 lg:grid-cols-3">
                    <Trends trend={trend} className="lg:col-span-2" />

                    <Panel title="Last 30 days">
                        <div className="space-y-6">
                            <div>
                                <h3 className="mb-3 text-sm font-medium text-muted-foreground">
                                    Order outcomes
                                </h3>
                                <StatusMixBar counts={statusMix} />
                            </div>

                            <div>
                                <h3 className="mb-3 text-sm font-medium text-muted-foreground">
                                    Top services
                                </h3>
                                <TopServices services={topServices.slice(0, 3)} />
                            </div>
                        </div>
                    </Panel>
                </div>

                {showProfit && <ProfitCard profit={profit} />}

                <div
                    className={`grid grid-cols-1 gap-4 sm:gap-6 ${recentTickets.length > 0 ? 'lg:grid-cols-3' : ''}`}
                >
                    <Panel
                        title="Recent orders"
                        className={recentTickets.length > 0 ? 'lg:col-span-2' : ''}
                    >
                        {recentOrders.length === 0 ? (
                            <Empty>
                                Nothing yet. Orders your customers place will appear here.
                            </Empty>
                        ) : (
                            <ul className="-my-2 divide-y divide-border/60">
                                {recentOrders.map((order, index) => (
                                    <li
                                        key={order.id}
                                        className={`items-center justify-between gap-3 py-3 ${
                                            index >= 5 ? 'hidden sm:flex' : 'flex'
                                        }`}
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm">{order.service ?? '—'}</p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {order.customer}
                                                {order.quantity
                                                    ? ` · ${order.quantity.toLocaleString('en-US')}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <div className="shrink-0 text-right">
                                            <p className="text-sm [font-variant-numeric:tabular-nums]">
                                                {order.amount !== null ? money(order.amount) : '—'}
                                            </p>
                                            <StatusPill status={order.status} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    {recentTickets.length > 0 && (
                        <Panel
                            title="Support"
                            hint={
                                openTickets === 1
                                    ? '1 needs an answer'
                                    : `${openTickets} need an answer`
                            }
                        >
                            <ul className="-my-2 divide-y divide-border/60">
                                {recentTickets.map((ticket) => (
                                    <li key={ticket.id} className="py-3">
                                        <p className="truncate text-sm">
                                            {ticket.subject ?? 'No subject'}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {ticket.customer} · {ticket.status}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}
                </div>

                <Panel title="Panels" hint="Where your orders are fulfilled">
                    {panels.length === 0 ? (
                        <Empty>No panel connected.</Empty>
                    ) : (
                        <ul className="-my-2 divide-y divide-border/60">
                            {panels.map((panel) => (
                                <PanelRow key={panel.id} panel={panel} />
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AuthenticatedLayout>
    );
}

/** The one container every block uses, so spacing and corners stay uniform. */
function Panel({
    title,
    hint,
    action,
    className = '',
    children,
}: {
    title: string;
    hint?: string;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section className={`min-w-0 rounded-2xl border border-border bg-card p-4 sm:p-5 ${className}`}>
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

/**
 * One bot's health, as a chip. The state colour is paired with an icon and the
 * state written out — never carried by hue alone. Numbers and counts live in
 * the tooltip rather than crowding a phone screen.
 */
function BotChip({
    name,
    icon: Icon,
    health,
}: {
    name: string;
    icon: LucideIcon;
    health: BotHealth;
}) {
    const state = BOT_STATE[health.state];
    const StateIcon = state.icon;
    const numbers = health.numbers.map((number) => number.display).join(', ');

    return (
        <div
            className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card py-1.5 pl-2.5 pr-3 text-xs sm:gap-2 sm:pl-3 sm:pr-3.5 sm:text-sm"
            title={
                health.numbersConnected === 0
                    ? state.hint
                    : `${numbers} · ${health.messagesToday} messages today`
            }
        >
            <Icon className="size-3.5 shrink-0 text-muted-foreground" />
            <span className="font-medium">{name}</span>
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                <StateIcon className="size-3.5 shrink-0" style={{ color: state.color }} />
                {state.label}
            </span>
        </div>
    );
}

/** What is left to set up: a slim banner, with the step list one tap away. */
function SetupBanner({
    setup,
    incomplete,
}: {
    setup: Props['setup'];
    incomplete: Props['setup']['steps'];
}) {
    const total = setup.steps.length;
    const percent = Math.round((setup.completed / total) * 100);

    return (
        <section className="rounded-2xl border border-primary/25 bg-primary/5 p-4 sm:p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <h2 className="font-heading font-bold">
                        {setup.readyToGoLive ? 'Finish setting up' : 'Your shop is not live yet'}
                    </h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {setup.readyToGoLive
                            ? 'Everything required is done — only optional bits remain.'
                            : `${incomplete.length} ${incomplete.length === 1 ? 'step' : 'steps'} left before customers can order.`}
                    </p>
                </div>

                <Button asChild className="w-full sm:w-auto">
                    <Link href={route('onboarding')}>
                        Continue setup
                        <ArrowRight className="size-4" />
                    </Link>
                </Button>
            </div>

            {/* A meter, not a two-slice pie: one ratio against a limit. */}
            <div className="mt-3 flex items-center gap-3">
                <div className="h-1.5 flex-1 rounded-full bg-muted">
                    <div
                        className="h-full rounded-full bg-primary transition-all duration-500"
                        style={{ width: `${percent}%` }}
                        role="progressbar"
                        aria-valuenow={setup.completed}
                        aria-valuemin={0}
                        aria-valuemax={total}
                        aria-label="Setup progress"
                    />
                </div>
                <span className="shrink-0 text-xs text-muted-foreground [font-variant-numeric:tabular-nums]">
                    {setup.completed}/{total}
                </span>
            </div>

            <details className="group mt-3">
                <summary className="flex cursor-pointer list-none items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground">
                    See the steps
                    <ChevronDown className="size-3.5 transition-transform group-open:rotate-180" />
                </summary>
                <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                    {setup.steps.map((step) => (
                        <li
                            key={step.key}
                            className="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            {step.complete ? (
                                <CheckCircle2
                                    className="size-3.5 shrink-0"
                                    style={{ color: STATUS_COLORS.completed }}
                                />
                            ) : (
                                <Clock className="size-3.5 shrink-0" />
                            )}
                            <span className="min-w-0 truncate">{step.title}</span>
                            {step.skipped && !step.complete ? (
                                <span className="opacity-70">(skipped)</span>
                            ) : (
                                !step.required &&
                                !step.complete && <span className="opacity-70">(optional)</span>
                            )}
                        </li>
                    ))}
                </ul>
            </details>
        </section>
    );
}

/**
 * The four headline numbers in one card. Hairline dividers (a 1px gap over a
 * border-coloured ground) rather than four boxes, so it reads as one summary
 * and sits as a tidy 2×2 on a phone.
 */
function Overview({ kpis }: { kpis: Props['kpis'] }) {
    return (
        <section
            aria-label="Key figures"
            className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-border bg-border lg:grid-cols-4"
        >
            <Figure label="Revenue" value={money(kpis.revenue.value)} delta={kpis.revenue.delta} />
            <Figure
                label="Orders"
                value={formatCompact(kpis.orders.value)}
                delta={kpis.orders.delta}
            />
            <Figure
                label="New customers"
                value={formatCompact(kpis.customers.value)}
                delta={kpis.customers.delta}
                note={`${formatCompact(kpis.customers.total ?? 0)} in total`}
            />
            <Figure
                label="Customer wallets"
                value={money(kpis.walletsHeld)}
                note="Not spent yet"
            />
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
    delta?: number | null;
    note?: string;
}) {
    const hasDelta = delta !== null && delta !== undefined;
    const flat = hasDelta && delta === 0;
    const DeltaIcon = flat ? null : hasDelta && delta! > 0 ? ArrowUpRight : ArrowDownRight;

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
                                    : delta! > 0
                                      ? 'text-[#006300] dark:text-[#0ca30c]'
                                      : 'text-destructive'
                            }`}
                        >
                            {DeltaIcon && <DeltaIcon className="size-3.5" />}
                            {Math.abs(delta!).toFixed(1)}%
                        </span>
                        <span className="text-muted-foreground">vs last 30 days</span>
                    </>
                ) : (
                    <span className="text-muted-foreground">{note}</span>
                )}
            </p>
        </div>
    );
}

/**
 * Revenue and orders share one chart with a switch, instead of two charts
 * stacked on a phone. Every chart keeps a table twin — a tooltip must never be
 * the only way to reach a value.
 */
function Trends({ trend, className = '' }: { trend: Props['trend']; className?: string }) {
    const [metric, setMetric] = useState<'revenue' | 'orders'>('revenue');
    const [showTable, setShowTable] = useState(false);

    const isRevenue = metric === 'revenue';
    const points = trend.map((day) => ({
        date: day.date,
        value: isRevenue ? day.revenue : day.orders,
    }));
    const format = (value: number) =>
        isRevenue ? money(value) : Math.round(value).toLocaleString('en-US');

    return (
        <Panel
            title="Trends"
            hint="Last 14 days"
            className={className}
            action={
                <div className="flex items-center gap-1">
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
                    <Button
                        variant="ghost"
                        size="sm"
                        className="hidden sm:inline-flex"
                        onClick={() => setShowTable(!showTable)}
                    >
                        {showTable ? 'Chart' : 'Table'}
                    </Button>
                </div>
            }
        >
            {showTable ? (
                <div className="scroll-slim max-h-64 overflow-y-auto pr-2">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-border text-left text-xs text-muted-foreground">
                                <th className="pb-2 font-medium">Day</th>
                                <th className="pb-2 text-right font-medium capitalize">{metric}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {points.map((point) => (
                                <tr
                                    key={point.date}
                                    className="border-b border-border/60 last:border-0"
                                >
                                    <td className="py-2">{point.date}</td>
                                    <td className="py-2 text-right [font-variant-numeric:tabular-nums]">
                                        {format(point.value)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <TrendChart
                    points={points}
                    variant={isRevenue ? 'area' : 'column'}
                    label={isRevenue ? 'Revenue' : 'Orders'}
                    formatValue={format}
                />
            )}
        </Panel>
    );
}

/**
 * What the shop actually kept, and which services are eating it. One card:
 * the headline and its two inputs on top, the thin-margin services below,
 * and a warning only when something is priced under cost.
 */
function ProfitCard({ profit }: { profit: Props['profit'] }) {
    const { summary, byService, underwater } = profit;
    const [showAll, setShowAll] = useState(false);

    const losing = summary.profit < 0;
    const shown = showAll ? byService : byService.slice(0, 3);

    return (
        <Panel title="Profit" hint="What you kept, last 30 days">
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                <p
                    className="font-heading text-3xl font-extrabold tracking-tight [font-variant-numeric:tabular-nums]"
                    style={losing ? { color: STATUS_COLORS.failed } : undefined}
                >
                    {money(summary.profit)}
                </p>

                <dl className="flex gap-5 text-sm">
                    <div>
                        <dt className="text-xs text-muted-foreground">Sold for</dt>
                        <dd className="[font-variant-numeric:tabular-nums]">
                            {money(summary.revenue)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">Cost</dt>
                        <dd className="[font-variant-numeric:tabular-nums]">
                            {money(summary.cost)}
                        </dd>
                    </div>
                    {summary.margin !== null && (
                        <div>
                            <dt className="text-xs text-muted-foreground">Margin</dt>
                            <dd className="font-semibold [font-variant-numeric:tabular-nums]">
                                {summary.margin}%
                            </dd>
                        </div>
                    )}
                </dl>
            </div>

            {/* A margin drawn from a fraction of the orders has to say so,
                or it reads as the whole picture. */}
            {summary.coverage !== null && summary.coverage < 100 && (
                <p className="mt-3 text-xs text-muted-foreground">
                    Based on {summary.measuredOrders} of {summary.totalOrders} paid orders — the
                    rest were placed before costs were recorded.
                </p>
            )}

            {/* Read from the catalogue rather than from orders, so a badly
                priced service is caught before anyone buys one. */}
            {underwater.length > 0 && (
                <div className="mt-4 rounded-xl border border-destructive/30 bg-destructive/5 p-3.5">
                    <p
                        className="flex items-center gap-1.5 text-sm font-semibold"
                        style={{ color: STATUS_COLORS.failed }}
                    >
                        <TriangleAlert className="size-4 shrink-0" />
                        {underwater.length} {underwater.length === 1 ? 'service is' : 'services are'}{' '}
                        priced at or below cost
                    </p>
                    <ul className="mt-2 space-y-1 text-xs text-muted-foreground">
                        {underwater.slice(0, 3).map((service) => (
                            <li key={service.id} className="flex justify-between gap-3">
                                <span className="min-w-0 truncate">{service.name}</span>
                                <span className="shrink-0 [font-variant-numeric:tabular-nums]">
                                    {money(service.myPrice)} vs {money(service.costPrice)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <Link
                        href={route('services.index')}
                        className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                    >
                        Fix pricing
                        <ArrowRight className="size-3" />
                    </Link>
                </div>
            )}

            {byService.length > 0 && (
                <div className="mt-5 border-t border-border pt-4">
                    <h3 className="mb-3 text-sm font-medium text-muted-foreground">
                        Thinnest margins first
                    </h3>
                    <ul className="space-y-3">
                        {shown.map((service) => (
                            <li key={service.name} className="text-sm">
                                <div className="flex items-baseline justify-between gap-3">
                                    <span className="min-w-0 truncate">{service.name}</span>
                                    <span
                                        className="shrink-0 [font-variant-numeric:tabular-nums]"
                                        style={
                                            service.profit < 0
                                                ? { color: STATUS_COLORS.failed }
                                                : undefined
                                        }
                                    >
                                        {money(service.profit)}
                                        {service.margin !== null && (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                {service.margin}%
                                            </span>
                                        )}
                                    </span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {service.orders} {service.orders === 1 ? 'order' : 'orders'} ·{' '}
                                    {money(service.revenue)} in, {money(service.cost)} out
                                </p>
                            </li>
                        ))}
                    </ul>
                    {byService.length > 3 && (
                        <button
                            type="button"
                            onClick={() => setShowAll(!showAll)}
                            className="mt-3 text-xs font-semibold text-primary hover:underline"
                        >
                            {showAll ? 'Show fewer' : `Show all ${byService.length}`}
                        </button>
                    )}
                </div>
            )}
        </Panel>
    );
}

function TopServices({ services }: { services: Props['topServices'] }) {
    if (services.length === 0) {
        return <Empty>No orders yet — your best sellers will show up here.</Empty>;
    }

    return (
        <ul className="space-y-3">
            {services.map((service) => {
                const share = (service.revenue / Math.max(services[0].revenue, 1)) * 100;

                return (
                    <li key={service.name}>
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="min-w-0 truncate">{service.name}</span>
                            <span className="shrink-0 text-muted-foreground [font-variant-numeric:tabular-nums]">
                                {money(service.revenue)}
                                <span className="ml-2 text-xs">
                                    {service.orders} {service.orders === 1 ? 'order' : 'orders'}
                                </span>
                            </span>
                        </div>
                        <div className="mt-1.5 h-1.5 w-full rounded-full bg-muted">
                            <div
                                className="h-full rounded-full"
                                style={{ width: `${share}%`, background: 'var(--color-chart-1)' }}
                            />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * One panel: what it holds, and whether it is answering.
 *
 * A panel that has stopped responding is the failure a reseller cannot
 * otherwise see — the balance beside it is simply the last figure we managed
 * to read, and showing it alone would keep claiming everything is fine. So the
 * state leads and the balance is marked stale, rather than the reverse.
 *
 * Same rule as the bot chips: an icon and words carry the state, never hue.
 */
function PanelRow({ panel }: { panel: Props['panels'][number] }) {
    const down = panel.status === 'error';

    return (
        <li className="flex items-center justify-between gap-3 py-3 text-sm">
            <span className="flex min-w-0 items-center gap-2.5">
                <Package className="size-4 shrink-0 text-muted-foreground" />
                <span className="min-w-0">
                    <span className="block truncate">{panel.name}</span>
                    {down && (
                        <span
                            className="flex items-center gap-1 text-xs"
                            style={{ color: STATUS_COLORS.failed }}
                        >
                            <XCircle className="size-3 shrink-0" />
                            Not responding
                        </span>
                    )}
                    {!down && panel.lowBalance && (
                        <span
                            className="flex items-center gap-1 text-xs"
                            style={{ color: STATUS_COLORS.pending }}
                        >
                            <TriangleAlert className="size-3 shrink-0" />
                            Running low — top up
                        </span>
                    )}
                </span>
            </span>
            <span className="shrink-0 text-right">
                {panel.balance !== null ? (
                    <span
                        className={`[font-variant-numeric:tabular-nums] ${
                            down ? 'text-muted-foreground line-through' : ''
                        }`}
                    >
                        {formatMoney(panel.balance, panel.currency ?? 'USD')}
                    </span>
                ) : (
                    <span className="text-muted-foreground">Balance unknown</span>
                )}
            </span>
        </li>
    );
}

function StatusPill({ status }: { status: OrderStatus }) {
    const Icon = status === 'completed' ? CheckCircle2 : status === 'pending' ? Clock : XCircle;

    return (
        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
            <Icon className="size-3 shrink-0" style={{ color: STATUS_COLORS[status] }} />
            {STATUS_LABELS[status]}
        </span>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="text-sm text-muted-foreground">{children}</p>;
}
