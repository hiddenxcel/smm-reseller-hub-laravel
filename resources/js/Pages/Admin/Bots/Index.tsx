import TrendChart from '@/components/charts/TrendChart';
import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { Bot, LifeBuoy } from 'lucide-react';
import { dateTime, Empty } from '../bits';
import {
    BotDefaults,
    BotKpis,
    BotTrendPoint,
    SilentBot,
    TemplateRow,
    TicketStats,
} from '../types';
import TemplatesTab from './TemplatesTab';

type Props = {
    bot: 'order' | 'support';
    tab: string;
    kpis: BotKpis;
    canManage: boolean;
    trend?: BotTrendPoint[];
    silent?: SilentBot[];
    tickets?: TicketStats | null;
    templates?: TemplateRow[];
    languages?: Array<{ code: string; name: string }>;
    lang?: string;
    defaults?: BotDefaults;
};

const TABS = ['overview', 'templates', 'defaults'] as const;

const LABELS: Record<string, string> = {
    overview: 'Overview',
    templates: 'System templates',
    defaults: 'Defaults',
};

export default function BotsIndex(props: Props) {
    const { bot, tab, kpis } = props;

    const title = bot === 'support' ? 'Support Bot' : 'Order Bot';
    const Icon = bot === 'support' ? LifeBuoy : Bot;

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5">
                        <Icon className="size-6 text-muted-foreground" />
                        <div>
                            <h1 className="font-heading text-xl font-extrabold">{title}</h1>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                How this bot is doing across every reseller.
                            </p>
                        </div>
                    </div>

                    {/* The other bot, one click away — they are compared often. */}
                    <Link
                        href={route('admin.bots', [
                            bot === 'support' ? 'order' : 'support',
                            tab,
                        ])}
                        className="rounded-lg border border-border px-3 py-1.5 text-sm transition-colors hover:bg-accent"
                    >
                        Switch to {bot === 'support' ? 'Order Bot' : 'Support Bot'}
                    </Link>
                </div>
            }
        >
            <Head title={`${title} — Control`} />

            <KpiRow kpis={kpis} bot={bot} />

            <div className="scroll-slim mt-6 flex gap-1 overflow-x-auto border-b border-border">
                {TABS.map((key) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => router.get(route('admin.bots', [bot, key]))}
                        className={[
                            '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm transition-colors',
                            tab === key
                                ? 'border-primary font-semibold text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        ].join(' ')}
                        aria-current={tab === key ? 'page' : undefined}
                    >
                        {LABELS[key]}
                    </button>
                ))}
            </div>

            <div className="mt-4">
                {tab === 'templates' ? (
                    <TemplatesTab
                        bot={bot}
                        rows={props.templates ?? []}
                        languages={props.languages ?? []}
                        lang={props.lang ?? 'en'}
                        canManage={props.canManage}
                    />
                ) : tab === 'defaults' ? (
                    <DefaultsTab defaults={props.defaults} />
                ) : (
                    <OverviewTab
                        bot={bot}
                        trend={props.trend}
                        silent={props.silent}
                        tickets={props.tickets}
                    />
                )}
            </div>
        </AdminLayout>
    );
}

function KpiRow({ kpis, bot }: { kpis: BotKpis; bot: string }) {
    const tiles = [
        { label: 'Paying', value: kpis.subscribed.toLocaleString() },
        { label: 'In sandbox', value: kpis.sandbox.toLocaleString() },
        { label: 'Numbers connected', value: kpis.numbersConnected.toLocaleString() },
        { label: 'Messages today', value: kpis.messagesToday.toLocaleString() },
        { label: 'Messages (7d)', value: kpis.messages7d.toLocaleString() },
        {
            label: 'Silent 48h',
            value: kpis.silent.toLocaleString(),
            // The one figure here that is a problem rather than a measurement.
            alert: kpis.silent > 0,
        },
    ];

    return (
        <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
            {tiles.map((tile) => (
                <div
                    key={tile.label}
                    className={[
                        'rounded-xl border bg-card p-4',
                        tile.alert ? 'border-amber-500/40' : 'border-border',
                    ].join(' ')}
                >
                    <p className="text-xs text-muted-foreground">{tile.label}</p>
                    <p
                        className={[
                            'font-heading mt-1 text-xl font-extrabold tabular-nums',
                            tile.alert ? 'text-amber-700 dark:text-amber-300' : '',
                        ].join(' ')}
                    >
                        {tile.value}
                    </p>
                </div>
            ))}
        </div>
    );
}

function OverviewTab({
    bot,
    trend,
    silent,
    tickets,
}: {
    bot: string;
    trend?: BotTrendPoint[];
    silent?: SilentBot[];
    tickets?: TicketStats | null;
}) {
    return (
        <div className="space-y-4">
            <div className="rounded-xl border border-border bg-card p-5">
                <h2 className="font-heading font-bold">Message volume</h2>
                <p className="mb-4 text-sm text-muted-foreground">
                    Both directions, last 14 days, across every reseller
                </p>

                <Deferred
                    data="trend"
                    fallback={<div className="h-[220px] animate-pulse rounded-lg bg-muted" />}
                >
                    {trend && trend.some((point) => point.messages > 0) ? (
                        <TrendChart
                            points={trend.map((point) => ({
                                date: point.date,
                                value: point.messages,
                            }))}
                            variant="column"
                            formatValue={(value) => value.toLocaleString()}
                            label="Messages"
                        />
                    ) : (
                        <Empty>No messages in this window.</Empty>
                    )}
                </Deferred>
            </div>

            {bot === 'support' && (
                <Deferred data="tickets" fallback={<CardSkeleton />}>
                    <TicketPanel tickets={tickets} />
                </Deferred>
            )}

            <div className="rounded-xl border border-border bg-card p-5">
                <h2 className="font-heading font-bold">Connected but silent</h2>
                <p className="mb-4 text-sm text-muted-foreground">
                    A number attached, nothing sent in 48 hours — usually stalled
                    setup rather than churn.
                </p>

                <Deferred data="silent" fallback={<ListSkeleton />}>
                    {silent && silent.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[36rem] text-sm">
                                <thead>
                                    <tr className="border-b border-border text-left text-xs uppercase tracking-wider text-muted-foreground">
                                        <th className="px-2 py-2 font-semibold">Reseller</th>
                                        <th className="px-2 py-2 font-semibold">Number</th>
                                        <th className="px-2 py-2 font-semibold">Status</th>
                                        <th className="px-2 py-2 font-semibold">Last reply</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {silent.map((row) => (
                                        <tr
                                            key={`${row.tenantId}-${row.number}`}
                                            className="border-b border-border last:border-0"
                                        >
                                            <td className="px-2 py-2">
                                                <Link
                                                    href={route('admin.tenants.show', row.tenantId)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {row.tenant}
                                                </Link>
                                            </td>
                                            <td className="px-2 py-2 text-muted-foreground">
                                                {row.number ?? '—'}
                                            </td>
                                            <td className="px-2 py-2 text-muted-foreground">
                                                {row.status ?? '—'}
                                            </td>
                                            <td className="px-2 py-2 text-muted-foreground">
                                                {row.lastReplyAt
                                                    ? dateTime(row.lastReplyAt)
                                                    : 'Never'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <Empty>Every connected number has spoken recently.</Empty>
                    )}
                </Deferred>
            </div>
        </div>
    );
}

function TicketPanel({ tickets }: { tickets?: TicketStats | null }) {
    if (!tickets) {
        return null;
    }

    const tiles = [
        { label: 'Open', value: tickets.open },
        { label: 'Pending', value: tickets.pending },
        { label: 'With a person', value: tickets.handedOver, alert: true },
        { label: 'Resolved', value: tickets.resolved },
        { label: 'Closed', value: tickets.closed },
    ];

    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <h2 className="font-heading font-bold">Ticket queue</h2>
            <p className="mb-4 text-sm text-muted-foreground">
                Across every reseller. "With a person" means the bot is staying
                quiet until staff hand the conversation back.
            </p>

            <div className="grid gap-3 sm:grid-cols-5">
                {tiles.map((tile) => (
                    <div key={tile.label}>
                        <p className="text-xs text-muted-foreground">{tile.label}</p>
                        <p
                            className={[
                                'font-heading mt-0.5 text-lg font-extrabold tabular-nums',
                                tile.alert && tile.value > 0
                                    ? 'text-amber-700 dark:text-amber-300'
                                    : '',
                            ].join(' ')}
                        >
                            {tile.value.toLocaleString()}
                        </p>
                    </div>
                ))}
            </div>

            <Link
                href={route('admin.tickets.index')}
                className="mt-4 inline-block text-sm font-medium text-primary hover:underline"
            >
                Open the ticket list →
            </Link>
        </div>
    );
}

/**
 * What a new reseller inherits.
 *
 * Read-only: these come from BotSettings::DEFAULTS, which is code rather than
 * data. Shown so an admin can see what a reseller starts with without opening
 * the source.
 */
function DefaultsTab({ defaults }: { defaults?: BotDefaults }) {
    if (!defaults) {
        return <Empty>No defaults to show.</Empty>;
    }

    const groups: Array<[string, Record<string, unknown>]> = [
        ['Commands', defaults.commands],
        ['Anti-spam', defaults.spam],
        ['Responses', defaults.response],
        ['Shop', defaults.shop],
    ];

    return (
        <div className="space-y-4">
            <p className="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                Every reseller starts with these and keeps them until they change
                something of their own. They are set in code and deployed, not
                edited here — a reseller's own settings are theirs to change.
            </p>

            <div className="grid gap-4 sm:grid-cols-2">
                {groups.map(([title, values]) => (
                    <div key={title} className="rounded-xl border border-border bg-card p-5">
                        <h3 className="font-heading mb-3 text-sm font-bold">{title}</h3>
                        <dl className="divide-y divide-border">
                            {Object.entries(values).map(([key, value]) => (
                                <div
                                    key={key}
                                    className="flex items-center justify-between gap-4 py-2 text-sm"
                                >
                                    <dt className="text-muted-foreground">
                                        {key.replace(/_/g, ' ')}
                                    </dt>
                                    <dd className="font-medium tabular-nums">
                                        {typeof value === 'boolean'
                                            ? value
                                                ? 'On'
                                                : 'Off'
                                            : String(value)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                ))}
            </div>
        </div>
    );
}

function CardSkeleton() {
    return <div className="h-40 animate-pulse rounded-xl bg-muted" />;
}

function ListSkeleton() {
    return (
        <div className="space-y-2">
            {[0, 1, 2].map((i) => (
                <div key={i} className="h-8 animate-pulse rounded bg-muted" />
            ))}
        </div>
    );
}
