import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { dateTime, Empty, money, shortDate } from '../bits';
import {
    ActivityEntry,
    TenantCustomer,
    TenantGatewayRow,
    TenantNumberRow,
    TenantOrder,
    TenantOverview,
    TenantPanelRow,
    TenantPayment,
    TenantRentalRow,
    TenantSubscription,
} from '../types';

const TABS = [
    'overview',
    'subscriptions',
    'orders',
    'customers',
    'payments',
    'panels',
    'numbers',
    'activity',
] as const;

type Tab = (typeof TABS)[number];

const LABELS: Record<Tab, string> = {
    overview: 'Overview',
    subscriptions: 'Subscriptions',
    orders: 'Orders',
    customers: 'Customers',
    payments: 'Payments',
    panels: 'Panels',
    numbers: 'Numbers',
    activity: 'Activity',
};

/**
 * One reseller's detail, a tab at a time.
 *
 * Tabs are fetched over JSON rather than as Inertia visits: the page is not
 * navigating, it is filling in a panel that is already on screen. The URL is
 * still updated so a tab stays linkable — an admin pasting "look at their
 * payments" into a ticket should land on the payments tab.
 */
export default function TenantTabs({
    tenantId,
    tab,
    overview,
}: {
    tenantId: number;
    tab: string;
    overview: TenantOverview;
}) {
    const initial = (TABS as readonly string[]).includes(tab)
        ? (tab as Tab)
        : 'overview';

    const [current, setCurrent] = useState<Tab>(initial);
    const [data, setData] = useState<Record<string, unknown> | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (current === 'overview') {
            setData(null);

            return;
        }

        let cancelled = false;

        setLoading(true);

        window.axios
            .get(route('admin.tenants.show', [tenantId, current]))
            .then((response) => {
                if (!cancelled) {
                    setData(response.data);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [current, tenantId]);

    const open = (next: Tab) => {
        setCurrent(next);

        // Keep the URL honest without a server round trip — the panel has
        // already asked for what it needs.
        router.visit(route('admin.tenants.show', [tenantId, next]), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: [],
        });
    };

    return (
        <div className="mt-6">
            <div className="scroll-slim flex gap-1 overflow-x-auto border-b border-border">
                {TABS.map((key) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => open(key)}
                        className={[
                            '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm transition-colors',
                            current === key
                                ? 'border-primary font-semibold text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        ].join(' ')}
                        aria-current={current === key ? 'page' : undefined}
                    >
                        {LABELS[key]}
                    </button>
                ))}
            </div>

            <div className="mt-4">
                {current === 'overview' ? (
                    <Overview overview={overview} />
                ) : loading ? (
                    <TableSkeleton />
                ) : (
                    <TabBody tab={current} data={data} />
                )}
            </div>
        </div>
    );
}

function TabBody({
    tab,
    data,
}: {
    tab: Tab;
    data: Record<string, unknown> | null;
}) {
    if (!data) {
        return <Empty>Nothing to show.</Empty>;
    }

    switch (tab) {
        case 'subscriptions':
            return <Subscriptions rows={(data.subscriptions ?? []) as TenantSubscription[]} />;
        case 'orders':
            return <Orders rows={(data.orders ?? []) as TenantOrder[]} />;
        case 'customers':
            return <Customers rows={(data.customers ?? []) as TenantCustomer[]} />;
        case 'payments':
            return <Payments rows={(data.payments ?? []) as TenantPayment[]} />;
        case 'panels':
            return (
                <Panels
                    panels={(data.panels ?? []) as TenantPanelRow[]}
                    gateways={(data.gateways ?? []) as TenantGatewayRow[]}
                />
            );
        case 'numbers':
            return (
                <Numbers
                    numbers={(data.numbers ?? []) as TenantNumberRow[]}
                    rentals={(data.rentals ?? []) as TenantRentalRow[]}
                />
            );
        case 'activity':
            return <Activity rows={(data.activity ?? []) as ActivityEntry[]} />;
        default:
            return null;
    }
}

function Overview({ overview }: { overview: TenantOverview }) {
    const rows: Array<[string, string]> = [
        ['Language', overview.lang.toUpperCase()],
        ['Referral code', overview.referralCode ?? '—'],
        ['Referral credit', money(overview.referralCredit)],
        ['Referred by', overview.referredBy?.name ?? '—'],
        ['First payment', overview.hasPaid ? 'Yes' : 'Not yet'],
        ['Joined', shortDate(overview.joinedAt)],
        ['Orders (30 days)', overview.stats.ordersLast30.toLocaleString()],
    ];

    return (
        <div className="rounded-xl border border-border bg-card">
            <dl className="divide-y divide-border">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-center justify-between gap-4 px-4 py-3 text-sm"
                    >
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="font-medium tabular-nums">{value}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

function Subscriptions({ rows }: { rows: TenantSubscription[] }) {
    if (rows.length === 0) {
        return <Empty>No subscriptions.</Empty>;
    }

    return (
        <Table headers={['Service', 'Plan', 'Status', 'Started', 'Ends']}>
            {rows.map((row) => (
                <tr key={row.id} className="border-b border-border last:border-0">
                    <Td>{row.service}</Td>
                    <Td muted>{row.plan ?? '—'}</Td>
                    <Td>
                        <span
                            className={[
                                'rounded px-1.5 py-0.5 text-xs font-medium',
                                row.status === 'active' && !row.expired
                                    ? 'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]'
                                    : row.status === 'sandbox'
                                      ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                      : 'bg-muted text-muted-foreground',
                            ].join(' ')}
                        >
                            {row.expired ? 'expired' : row.status}
                        </span>
                    </Td>
                    <Td muted>{shortDate(row.startsAt)}</Td>
                    <Td muted>{row.endsAt ? shortDate(row.endsAt) : 'Open'}</Td>
                </tr>
            ))}
        </Table>
    );
}

function Orders({ rows }: { rows: TenantOrder[] }) {
    if (rows.length === 0) {
        return <Empty>No orders.</Empty>;
    }

    return (
        <Table headers={['Service', 'Customer', 'Qty', 'Amount', 'Status', 'When']}>
            {rows.map((row) => (
                <tr key={row.id} className="border-b border-border last:border-0">
                    <Td>{row.service ?? '—'}</Td>
                    <Td muted>{row.customer ?? '—'}</Td>
                    <Td muted>{row.quantity?.toLocaleString() ?? '—'}</Td>
                    <Td>{money(row.amount)}</Td>
                    <Td muted>{row.status ?? row.paymentStatus ?? '—'}</Td>
                    <Td muted>{shortDate(row.at)}</Td>
                </tr>
            ))}
        </Table>
    );
}

function Customers({ rows }: { rows: TenantCustomer[] }) {
    if (rows.length === 0) {
        return <Empty>No customers.</Empty>;
    }

    return (
        <Table headers={['Phone', 'Name', 'Balance', 'Spent', 'Last seen']}>
            {rows.map((row) => (
                <tr key={row.id} className="border-b border-border last:border-0">
                    <Td>
                        {row.phone}
                        {row.blocked && (
                            <span className="ml-2 rounded bg-destructive/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-destructive">
                                Blocked
                            </span>
                        )}
                    </Td>
                    <Td muted>{row.name ?? '—'}</Td>
                    <Td>{money(row.balance)}</Td>
                    <Td muted>{money(row.totalSpent)}</Td>
                    <Td muted>{shortDate(row.lastSeenAt)}</Td>
                </tr>
            ))}
        </Table>
    );
}

function Payments({ rows }: { rows: TenantPayment[] }) {
    if (rows.length === 0) {
        return <Empty>No payments to the platform yet.</Empty>;
    }

    return (
        <Table headers={['Gateway', 'Reference', 'Amount', 'Credit', 'Status', 'When']}>
            {rows.map((row) => (
                <tr key={row.id} className="border-b border-border last:border-0">
                    <Td>{row.gateway}</Td>
                    <Td muted>
                        <span className="block max-w-[12rem] truncate font-mono text-xs">
                            {row.reference ?? '—'}
                        </span>
                    </Td>
                    <Td>{money(row.amount)}</Td>
                    <Td muted>
                        {row.creditApplied > 0 ? money(row.creditApplied) : '—'}
                    </Td>
                    <Td>
                        <span
                            className={[
                                'rounded px-1.5 py-0.5 text-xs font-medium',
                                row.status === 'success'
                                    ? 'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]'
                                    : row.status === 'failed'
                                      ? 'bg-destructive/10 text-destructive'
                                      : 'bg-muted text-muted-foreground',
                            ].join(' ')}
                        >
                            {row.status}
                        </span>
                    </Td>
                    <Td muted>{shortDate(row.at)}</Td>
                </tr>
            ))}
        </Table>
    );
}

function Panels({
    panels,
    gateways,
}: {
    panels: TenantPanelRow[];
    gateways: TenantGatewayRow[];
}) {
    return (
        <div className="space-y-6">
            <section>
                <h3 className="mb-2 text-sm font-semibold">SMM panels</h3>
                {panels.length === 0 ? (
                    <Empty>No panels connected.</Empty>
                ) : (
                    <Table headers={['Name', 'Type', 'Balance', 'Services', 'Checked', 'Status']}>
                        {panels.map((row) => (
                            <tr key={row.id} className="border-b border-border last:border-0">
                                <Td>{row.name ?? '—'}</Td>
                                <Td muted>{row.type ?? '—'}</Td>
                                <Td>
                                    {row.balance !== null
                                        ? `${row.currency ?? ''} ${row.balance.toLocaleString()}`.trim()
                                        : '—'}
                                </Td>
                                <Td muted>{row.services?.toLocaleString() ?? '—'}</Td>
                                <Td muted>{shortDate(row.checkedAt)}</Td>
                                <Td muted>{row.status ?? '—'}</Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </section>

            <section>
                <h3 className="mb-2 text-sm font-semibold">Payment gateways</h3>
                <p className="mb-2 text-xs text-muted-foreground">
                    Whether a gateway is connected. Credentials are never shown.
                </p>
                {gateways.length === 0 ? (
                    <Empty>No gateways connected.</Empty>
                ) : (
                    <Table headers={['Gateway', 'Status', 'Default']}>
                        {gateways.map((row) => (
                            <tr key={row.id} className="border-b border-border last:border-0">
                                <Td>{row.gateway}</Td>
                                <Td muted>{row.status ?? '—'}</Td>
                                <Td muted>{row.isDefault ? 'Yes' : '—'}</Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </section>
        </div>
    );
}

function Numbers({
    numbers,
    rentals,
}: {
    numbers: TenantNumberRow[];
    rentals: TenantRentalRow[];
}) {
    return (
        <div className="space-y-6">
            <section>
                <h3 className="mb-2 text-sm font-semibold">WhatsApp numbers</h3>
                {numbers.length === 0 ? (
                    <Empty>No numbers connected.</Empty>
                ) : (
                    <Table headers={['Number', 'Source', 'Bot', 'Status']}>
                        {numbers.map((row) => (
                            <tr key={row.id} className="border-b border-border last:border-0">
                                <Td>{row.display ?? '—'}</Td>
                                <Td muted>{row.source ?? '—'}</Td>
                                <Td muted>{row.bot ?? '—'}</Td>
                                <Td muted>{row.status ?? '—'}</Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </section>

            <section>
                <h3 className="mb-2 text-sm font-semibold">Rentals</h3>
                {rentals.length === 0 ? (
                    <Empty>No rented numbers.</Empty>
                ) : (
                    <Table headers={['Number', 'Country', 'Monthly', 'Started', 'Ends', 'Status']}>
                        {rentals.map((row) => (
                            <tr key={row.id} className="border-b border-border last:border-0">
                                <Td>{row.number ?? '—'}</Td>
                                <Td muted>{row.country ?? '—'}</Td>
                                <Td>
                                    {row.cost !== null
                                        ? `${row.currency ?? ''} ${row.cost.toLocaleString()}`.trim()
                                        : '—'}
                                </Td>
                                <Td muted>{shortDate(row.startsAt)}</Td>
                                <Td muted>{shortDate(row.endsAt)}</Td>
                                <Td muted>{row.status ?? '—'}</Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </section>
        </div>
    );
}

/**
 * The reseller's own actions and every admin action taken against them, in one
 * stream. Read together they answer "why did my account change?"; either alone
 * does not.
 */
function Activity({ rows }: { rows: ActivityEntry[] }) {
    if (rows.length === 0) {
        return <Empty>Nothing recorded yet.</Empty>;
    }

    return (
        <ul className="space-y-2">
            {rows.map((entry) => (
                <li
                    key={entry.id}
                    className="rounded-lg border border-border bg-card px-4 py-3 text-sm"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <span
                            className={[
                                'rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                entry.actorType === 'superadmin'
                                    ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                    : 'bg-muted text-muted-foreground',
                            ].join(' ')}
                        >
                            {entry.actorType === 'superadmin'
                                ? `admin: ${entry.actor ?? '?'}`
                                : 'reseller'}
                        </span>

                        <span className="font-medium">{entry.action}</span>

                        <span className="ml-auto text-xs text-muted-foreground">
                            {dateTime(entry.at)}
                        </span>
                    </div>

                    {entry.details && Object.keys(entry.details).length > 0 && (
                        <p className="mt-1.5 break-words font-mono text-[11px] text-muted-foreground">
                            {JSON.stringify(entry.details)}
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}

// ---- shared table pieces -------------------------------------------------

function Table({
    headers,
    children,
}: {
    headers: string[];
    children: React.ReactNode;
}) {
    return (
        <div className="overflow-x-auto rounded-xl border border-border bg-card">
            <table className="w-full min-w-[36rem] text-sm">
                <thead>
                    <tr className="border-b border-border text-left text-xs uppercase tracking-wider text-muted-foreground">
                        {headers.map((header) => (
                            <th key={header} className="px-4 py-2.5 font-semibold">
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

function Td({
    children,
    muted = false,
}: {
    children: React.ReactNode;
    muted?: boolean;
}) {
    return (
        <td
            className={[
                'px-4 py-2.5 tabular-nums',
                muted ? 'text-muted-foreground' : '',
            ].join(' ')}
        >
            {children}
        </td>
    );
}

function TableSkeleton() {
    return (
        <div className="space-y-2">
            {Array.from({ length: 6 }).map((_, index) => (
                <div key={index} className="h-10 animate-pulse rounded bg-muted" />
            ))}
        </div>
    );
}
