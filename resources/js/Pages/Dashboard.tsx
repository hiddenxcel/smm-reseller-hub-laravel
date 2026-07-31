import StatTile from '@/components/charts/StatTile';
import StatusMixBar from '@/components/charts/StatusMixBar';
import TrendChart from '@/components/charts/TrendChart';
import {
    OrderStatus,
    STATUS_COLORS,
    STATUS_LABELS,
    formatCompact,
    formatMoney,
} from '@/components/charts/chart-tokens';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Bot,
    CheckCircle2,
    CircleDollarSign,
    Clock,
    LifeBuoy,
    LucideIcon,
    MessageSquare,
    Package,
    Settings,
    ShoppingBag,
    TriangleAlert,
    UserPlus,
    Wallet,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

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
    panels: Array<{
        id: number;
        name: string;
        balance: number | null;
        currency: string | null;
        checkedAt: string | null;
        status: string;
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

export default function Dashboard({
    businessName,
    kpis,
    botStatus,
    trend,
    statusMix,
    topServices,
    panels,
    recentOrders,
    recentTickets,
    openTickets,
    setup,
}: Props) {
    const revenuePoints = trend.map((day) => ({ date: day.date, value: day.revenue }));
    const orderPoints = trend.map((day) => ({ date: day.date, value: day.orders }));

    const setupIncomplete = setup.steps.filter((step) => !step.complete);

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="space-y-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-heading text-2xl font-extrabold tracking-tight">
                            {businessName}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Here is how your shop has been doing.
                        </p>
                    </div>

                    {/* Two bots, sold separately and often on separate
                        numbers — one combined light would call both healthy
                        when only one was. */}
                    <div className="flex flex-wrap gap-2.5">
                        <BotHealthPill
                            name="Order bot"
                            icon={ShoppingBag}
                            health={botStatus.order}
                        />
                        <BotHealthPill
                            name="Support bot"
                            icon={LifeBuoy}
                            health={botStatus.support}
                        />
                    </div>
                </header>

                {/* Setup stays visible after go-live: payments is optional, so a
                    reseller can be live with something still undone. */}
                {setupIncomplete.length > 0 && (
                    <SetupCard setup={setup} incomplete={setupIncomplete} />
                )}

                <section aria-label="Key figures">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatTile
                            label="Revenue"
                            value={formatMoney(kpis.revenue.value)}
                            delta={kpis.revenue.delta}
                            icon={CircleDollarSign}
                            sparkline={trend.map((day) => day.revenue)}
                        />
                        <StatTile
                            label="Orders"
                            value={formatCompact(kpis.orders.value)}
                            delta={kpis.orders.delta}
                            icon={ShoppingBag}
                            sparkline={trend.map((day) => day.orders)}
                        />
                        <StatTile
                            label="New customers"
                            value={formatCompact(kpis.customers.value)}
                            delta={kpis.customers.delta}
                            icon={UserPlus}
                            footer={
                                <p className="text-xs text-muted-foreground">
                                    {formatCompact(kpis.customers.total ?? 0)} in total
                                </p>
                            }
                        />
                        <StatTile
                            label="Customer wallets"
                            value={formatMoney(kpis.walletsHeld)}
                            icon={Wallet}
                            footer={
                                <p className="text-xs text-muted-foreground">
                                    Money your customers have not spent yet
                                </p>
                            }
                        />
                    </div>
                </section>

                <section className="grid gap-6 lg:grid-cols-2" aria-label="Trends">
                    <ChartCard
                        title="Revenue"
                        subtitle="Successful payments, last 14 days"
                        points={revenuePoints}
                        formatValue={(value) => formatMoney(value)}
                    >
                        <TrendChart
                            points={revenuePoints}
                            variant="area"
                            label="Revenue"
                            formatValue={(value) => formatMoney(value)}
                        />
                    </ChartCard>

                    <ChartCard
                        title="Orders"
                        subtitle="Orders placed, last 14 days"
                        points={orderPoints}
                        formatValue={(value) => Math.round(value).toLocaleString('en-US')}
                    >
                        <TrendChart
                            points={orderPoints}
                            variant="column"
                            label="Orders"
                            formatValue={(value) => Math.round(value).toLocaleString('en-US')}
                        />
                    </ChartCard>
                </section>

                <section className="grid gap-6 lg:grid-cols-3" aria-label="Breakdown">
                    <Card title="Order outcomes" subtitle="Last 30 days">
                        <StatusMixBar counts={statusMix} />
                    </Card>

                    <Card
                        title="Top services"
                        subtitle="By revenue, last 30 days"
                        className="lg:col-span-2"
                    >
                        {topServices.length === 0 ? (
                            <Empty>No orders yet — your best sellers will show up here.</Empty>
                        ) : (
                            <ul className="space-y-3">
                                {topServices.map((service) => {
                                    const share =
                                        (service.revenue /
                                            Math.max(topServices[0].revenue, 1)) *
                                        100;

                                    return (
                                        <li key={service.name}>
                                            <div className="flex items-baseline justify-between gap-3 text-sm">
                                                <span className="min-w-0 truncate">
                                                    {service.name}
                                                </span>
                                                <span className="shrink-0 text-muted-foreground [font-variant-numeric:tabular-nums]">
                                                    {formatMoney(service.revenue)}
                                                    <span className="ml-2 text-xs">
                                                        {service.orders}{' '}
                                                        {service.orders === 1 ? 'order' : 'orders'}
                                                    </span>
                                                </span>
                                            </div>
                                            <div className="mt-1.5 h-1.5 w-full rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full"
                                                    style={{
                                                        width: `${share}%`,
                                                        background: 'var(--color-chart-1)',
                                                    }}
                                                />
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </Card>
                </section>

                <section className="grid gap-6 lg:grid-cols-3" aria-label="Activity">
                    <Card
                        title="Recent orders"
                        subtitle="The last few that came through"
                        className="lg:col-span-2"
                        action={
                            <Button variant="ghost" size="sm" disabled>
                                All orders
                                <ArrowRight className="size-3.5" />
                            </Button>
                        }
                    >
                        {recentOrders.length === 0 ? (
                            <Empty>
                                Nothing yet. Orders your customers place will appear here.
                            </Empty>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-left text-xs text-muted-foreground">
                                            <th className="pb-2 font-medium">Service</th>
                                            <th className="pb-2 font-medium">Customer</th>
                                            <th className="pb-2 text-right font-medium">Amount</th>
                                            <th className="pb-2 text-right font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recentOrders.map((order) => (
                                            <tr
                                                key={order.id}
                                                className="border-b border-border/60 last:border-0"
                                            >
                                                <td className="py-2.5 pr-3">
                                                    <span className="block max-w-[16rem] truncate">
                                                        {order.service ?? '—'}
                                                    </span>
                                                    {order.quantity && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {order.quantity.toLocaleString('en-US')}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-2.5 pr-3 text-muted-foreground">
                                                    {order.customer}
                                                </td>
                                                <td className="py-2.5 pr-3 text-right [font-variant-numeric:tabular-nums]">
                                                    {order.amount !== null
                                                        ? formatMoney(order.amount)
                                                        : '—'}
                                                </td>
                                                <td className="py-2.5 text-right">
                                                    <StatusPill status={order.status} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    <Card
                        title="Support"
                        subtitle={
                            openTickets === 1
                                ? '1 ticket needs an answer'
                                : `${openTickets} tickets need an answer`
                        }
                        action={
                            <Button variant="ghost" size="sm" disabled>
                                Inbox
                                <ArrowRight className="size-3.5" />
                            </Button>
                        }
                    >
                        {recentTickets.length === 0 ? (
                            <Empty>No tickets. Quiet is good.</Empty>
                        ) : (
                            <ul className="space-y-3">
                                {recentTickets.map((ticket) => (
                                    <li key={ticket.id} className="flex items-start gap-2.5">
                                        <LifeBuoy className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm">
                                                {ticket.subject ?? 'No subject'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {ticket.customer} · {ticket.status}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </section>

                <section className="grid gap-6 lg:grid-cols-3" aria-label="Panels and actions">
                    <Card
                        title="Panels"
                        subtitle="Where your orders are fulfilled"
                        className="lg:col-span-2"
                    >
                        {panels.length === 0 ? (
                            <Empty>No panel connected.</Empty>
                        ) : (
                            <ul className="space-y-3">
                                {panels.map((panel) => (
                                    <li
                                        key={panel.id}
                                        className="flex items-center justify-between gap-3 text-sm"
                                    >
                                        <span className="flex min-w-0 items-center gap-2.5">
                                            <Package className="size-4 shrink-0 text-muted-foreground" />
                                            <span className="min-w-0 truncate">{panel.name}</span>
                                        </span>
                                        <span className="shrink-0 text-right">
                                            {panel.balance !== null ? (
                                                <span className="[font-variant-numeric:tabular-nums]">
                                                    {formatMoney(
                                                        panel.balance,
                                                        panel.currency ?? 'USD',
                                                    )}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Balance unknown
                                                </span>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card title="Quick actions" subtitle="The things you do most">
                        <div className="grid gap-2">
                            <QuickAction
                                icon={Settings}
                                label="Setup & payments"
                                href={route('onboarding')}
                            />
                            <QuickAction icon={MessageSquare} label="Bot settings" disabled />
                            <QuickAction icon={Package} label="Services & pricing" disabled />
                            <QuickAction icon={Bot} label="Test your bot" href={route('onboarding.step', 'test')} />
                        </div>
                    </Card>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * One bot's health. The state colour is a status colour, so it is paired with
 * an icon and the state written out — never carried by hue alone.
 */
function BotHealthPill({
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
        <div className="flex items-center gap-2.5 rounded-xl border border-border bg-card px-3.5 py-2.5">
            <Icon className="size-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0">
                <p className="flex items-center gap-1.5 text-sm font-semibold">
                    {name}
                    <StateIcon className="size-3.5 shrink-0" style={{ color: state.color }} />
                    <span className="font-normal">{state.label}</span>
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    {health.numbersConnected === 0
                        ? state.hint
                        : `${numbers} · ${health.messagesToday} today`}
                </p>
            </div>
        </div>
    );
}

function SetupCard({
    setup,
    incomplete,
}: {
    setup: Props['setup'];
    incomplete: Props['setup']['steps'];
}) {
    const total = setup.steps.length;
    const percent = Math.round((setup.completed / total) * 100);

    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-heading font-bold">
                        {setup.readyToGoLive ? 'Finish setting up' : 'Your shop is not live yet'}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {setup.readyToGoLive
                            ? 'Everything required is done — these are the optional bits.'
                            : `${incomplete.length} ${incomplete.length === 1 ? 'step' : 'steps'} left before customers can order.`}
                    </p>
                </div>

                <Button asChild>
                    <Link href={route('onboarding')}>
                        Continue setup
                        <ArrowRight className="size-4" />
                    </Link>
                </Button>
            </div>

            {/* A meter, not a two-slice pie: one ratio against a limit. */}
            <div className="mt-4">
                <div className="flex items-center justify-between text-xs text-muted-foreground">
                    <span>
                        {setup.completed} of {total} done
                    </span>
                    <span className="[font-variant-numeric:tabular-nums]">{percent}%</span>
                </div>
                <div className="mt-1.5 h-2 w-full rounded-full bg-muted">
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
            </div>

            <ul className="mt-4 flex flex-wrap gap-x-5 gap-y-2">
                {setup.steps.map((step) => (
                    <li
                        key={step.key}
                        className="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        {step.complete ? (
                            <CheckCircle2
                                className="size-3.5"
                                style={{ color: STATUS_COLORS.completed }}
                            />
                        ) : (
                            <Clock className="size-3.5" />
                        )}
                        {step.title}
                        {!step.required && !step.complete && (
                            <span className="opacity-70">(optional)</span>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * A chart card with a table-view twin. Every chart has one — a tooltip must
 * never be the only way to reach a value.
 */
function ChartCard({
    title,
    subtitle,
    points,
    formatValue,
    children,
}: {
    title: string;
    subtitle: string;
    points: Array<{ date: string; value: number }>;
    formatValue: (value: number) => string;
    children: React.ReactNode;
}) {
    const [showTable, setShowTable] = useState(false);

    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="font-heading font-bold">{title}</h2>
                    <p className="text-sm text-muted-foreground">{subtitle}</p>
                </div>
                <Button variant="ghost" size="sm" onClick={() => setShowTable(!showTable)}>
                    {showTable ? 'Chart' : 'Table'}
                </Button>
            </div>

            <div className="mt-4">
                {showTable ? (
                    <div className="max-h-64 overflow-y-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border text-left text-xs text-muted-foreground">
                                    <th className="pb-2 font-medium">Day</th>
                                    <th className="pb-2 text-right font-medium">{title}</th>
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
                                            {formatValue(point.value)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    children
                )}
            </div>
        </div>
    );
}

function Card({
    title,
    subtitle,
    action,
    className = '',
    children,
}: {
    title: string;
    subtitle?: string;
    action?: React.ReactNode;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={`rounded-xl border border-border bg-card p-5 ${className}`}>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="font-heading font-bold">{title}</h2>
                    {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
                </div>
                {action}
            </div>
            <div className="mt-4">{children}</div>
        </div>
    );
}

function StatusPill({ status }: { status: OrderStatus }) {
    const Icon =
        status === 'completed' ? CheckCircle2 : status === 'pending' ? Clock : XCircle;

    return (
        <span className="inline-flex items-center gap-1.5 text-xs">
            <Icon className="size-3.5 shrink-0" style={{ color: STATUS_COLORS[status] }} />
            {STATUS_LABELS[status]}
        </span>
    );
}

function QuickAction({
    icon: Icon,
    label,
    href,
    disabled,
}: {
    icon: LucideIcon;
    label: string;
    href?: string;
    disabled?: boolean;
}) {
    const inner = (
        <>
            <Icon className="size-4 shrink-0" />
            <span className="flex-1 text-left">{label}</span>
            {disabled ? (
                <span className="text-xs text-muted-foreground">Soon</span>
            ) : (
                <ArrowRight className="size-3.5" />
            )}
        </>
    );

    const classes =
        'flex items-center gap-2.5 rounded-lg border border-border px-3 py-2.5 text-sm transition-colors';

    if (disabled || !href) {
        return (
            <span className={`${classes} cursor-not-allowed text-muted-foreground`}>{inner}</span>
        );
    }

    return (
        <Link href={href} className={`${classes} hover:border-primary/40 hover:bg-accent/40`}>
            {inner}
        </Link>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return <p className="text-sm text-muted-foreground">{children}</p>;
}
