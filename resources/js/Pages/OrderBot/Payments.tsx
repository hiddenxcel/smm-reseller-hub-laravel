import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Search,
    Wallet,
    X,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

type Row = {
    id: number;
    reference: string | null;
    gatewayReference: string | null;
    gateway: string;
    gatewayLabel: string;
    type: string;
    amount: number;
    status: 'success' | 'pending' | 'failed';
    customer: { name: string | null; phone: string } | null;
    orderId: number | null;
    orderService: string | null;
    createdAt: string | null;
};

type Filters = {
    status: string | null;
    gateway: string | null;
    type: string | null;
    range: number;
    q: string;
};

type Props = {
    payments: Row[];
    meta: { page: number; lastPage: number; total: number; from: number | null; to: number | null };
    filters: Filters;
    isFiltered: boolean;
    ranges: number[];
    summary: {
        received: number;
        paid: number;
        pending: { amount: number; count: number };
        failed: number;
    };
    tabCounts: { all: number; success: number; pending: number; failed: number };
    gateways: Array<{ code: string; label: string }>;
    currency: string;
};

const RANGE_LABELS: Record<number, string> = { 7: '7 days', 30: '30 days', 90: '90 days', 0: 'All' };

const TYPE_LABELS: Record<string, string> = {
    wallet_topup: 'Wallet top-up',
    order_payment: 'Order payment',
};

/** The list has room for a word, not a sentence; the dialog has the full wording. */
const SHORT_TYPES: Record<string, string> = {
    wallet_topup: 'Top-up',
    order_payment: 'Order',
};

/** "Snippe (Tanzania — TZS)" is "Snippe" in a row; the country is in the dialog. */
function shortGateway(label: string): string {
    return label.split(' (')[0];
}

const STATUS_TABS: Array<{ key: Filters['status']; label: string; count: keyof Props['tabCounts'] }> = [
    { key: null, label: 'All', count: 'all' },
    { key: 'success', label: 'Paid', count: 'success' },
    { key: 'pending', label: 'Pending', count: 'pending' },
    { key: 'failed', label: 'Failed', count: 'failed' },
];

/**
 * What the reseller's customers have paid them — money in, with the status of
 * each payment and the way to find one when somebody says "I paid".
 *
 * The totals are over the chosen window only, so typing in the search box never
 * moves them. Everything about the view lives in the query string: a filtered
 * list is a link that can be sent on and survives a refresh. What the reseller
 * pays the platform is on Billing, not here.
 */
export default function Payments({
    payments,
    meta,
    filters,
    isFiltered,
    ranges,
    summary,
    tabCounts,
    gateways,
    currency,
}: Props) {
    const [query, setQuery] = useState(filters.q);
    const [open, setOpen] = useState<Row | null>(null);

    const money = (value: number) =>
        new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: /^[A-Z]{3}$/.test(currency) ? currency : 'USD',
            maximumFractionDigits: value >= 1000 ? 0 : 2,
        }).format(value);

    /**
     * Only values that differ from the default reach the URL, so a bare
     * /order-bot/payments stays bare. A changed filter drops the page number:
     * the page that was number 4 rarely exists in the new, smaller list.
     */
    const go = (changes: Partial<Filters> & { page?: number }) => {
        const merged = { ...filters, ...changes };
        const next: Record<string, string | number> = {};

        if (merged.status) next.status = merged.status;
        if (merged.gateway) next.gateway = merged.gateway;
        if (merged.type) next.type = merged.type;
        if (merged.q) next.q = merged.q;
        if (merged.range !== 30) next.range = merged.range;
        if (changes.page && changes.page > 1) next.page = changes.page;

        router.get(route('order-bot.payments'), next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Payments" />

            <div className="mx-auto max-w-5xl space-y-4 sm:space-y-6">
                <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="min-w-0">
                        <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                            Payments
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            What your customers have paid you.
                        </p>
                    </div>

                    <nav
                        className="inline-flex rounded-xl border border-border bg-muted/50 p-1 text-sm font-medium"
                        aria-label="Time range"
                    >
                        {ranges.map((days) => (
                            <button
                                key={days}
                                type="button"
                                onClick={() => go({ range: days })}
                                className={`flex-1 rounded-lg px-3.5 py-1.5 text-center transition-colors sm:flex-none ${
                                    days === filters.range
                                        ? 'bg-card shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                                aria-pressed={days === filters.range}
                            >
                                {RANGE_LABELS[days] ?? `${days} days`}
                            </button>
                        ))}
                    </nav>
                </header>

                <section
                    aria-label="Totals"
                    className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-border bg-border lg:grid-cols-4"
                >
                    <Figure label="Received" value={money(summary.received)} accent />
                    <Figure
                        label="Payments"
                        value={summary.paid.toLocaleString('en-US')}
                        note="paid"
                    />
                    <Figure
                        label="Waiting"
                        value={money(summary.pending.amount)}
                        note={`${summary.pending.count} pending`}
                    />
                    <Figure
                        label="Failed"
                        value={summary.failed.toLocaleString('en-US')}
                        note="never arrived"
                    />
                </section>

                <div className="space-y-3">
                    <div
                        className="scroll-slim -mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0"
                        role="tablist"
                        aria-label="Filter by status"
                    >
                        {STATUS_TABS.map((tab) => {
                            const active = filters.status === tab.key;

                            return (
                                <button
                                    key={tab.label}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    onClick={() => go({ status: tab.key })}
                                    className={[
                                        'flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm transition-colors',
                                        active
                                            ? 'border-primary bg-primary/10 font-semibold text-foreground'
                                            : 'border-border text-muted-foreground hover:text-foreground',
                                    ].join(' ')}
                                >
                                    {tab.label}
                                    <span className="font-data text-xs tabular-nums opacity-70">
                                        {tabCounts[tab.count]}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    <div className="flex items-center gap-2">
                        <div className="relative min-w-0 flex-1">
                            <Search
                                className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <input
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                onKeyDown={(event) =>
                                    event.key === 'Enter' && go({ q: query.trim() })
                                }
                                onBlur={() => query.trim() !== filters.q && go({ q: query.trim() })}
                                placeholder="Search name, number or reference"
                                aria-label="Search payments"
                                className="h-10 w-full rounded-xl border border-border bg-background pl-9 pr-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                            />
                        </div>

                        {gateways.length > 1 && (
                            <select
                                value={filters.gateway ?? ''}
                                onChange={(event) => go({ gateway: event.target.value || null })}
                                aria-label="Filter by payment method"
                                className="h-10 max-w-[8.5rem] shrink-0 rounded-xl border border-border bg-background px-2.5 text-sm outline-none focus:border-ring"
                            >
                                <option value="">All methods</option>
                                {gateways.map((gateway) => (
                                    <option key={gateway.code} value={gateway.code}>
                                        {gateway.label}
                                    </option>
                                ))}
                            </select>
                        )}

                        <select
                            value={filters.type ?? ''}
                            onChange={(event) => go({ type: event.target.value || null })}
                            aria-label="Filter by kind of payment"
                            className="hidden h-10 shrink-0 rounded-xl border border-border bg-background px-2.5 text-sm outline-none focus:border-ring sm:block"
                        >
                            <option value="">Any kind</option>
                            {Object.entries(TYPE_LABELS).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {isFiltered && (
                        <button
                            type="button"
                            onClick={() => {
                                setQuery('');
                                router.get(route('order-bot.payments'), {}, { replace: true });
                            }}
                            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <X className="size-3.5" aria-hidden />
                            Clear filters
                        </button>
                    )}
                </div>

                {payments.length === 0 ? (
                    <Empty filtered={isFiltered} />
                ) : (
                    <>
                        {/* A list on every width: a payment is a person, a method, an
                            amount and a status, which reads better as a row than as
                            eight narrow columns. */}
                        <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                            {payments.map((payment) => (
                                <li key={payment.id}>
                                    <button
                                        type="button"
                                        onClick={() => setOpen(payment)}
                                        className="flex w-full items-center gap-3 p-3.5 text-left transition-colors hover:bg-accent/50 sm:p-4"
                                    >
                                        <StatusIcon status={payment.status} />

                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">
                                                {customerLabel(payment)}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {shortGateway(payment.gatewayLabel)} ·{' '}
                                                {SHORT_TYPES[payment.type] ?? payment.type} ·{' '}
                                                {shortDate(payment.createdAt)}
                                            </span>
                                        </span>

                                        <span className="shrink-0 text-right">
                                            <span
                                                className={`block text-sm font-semibold [font-variant-numeric:tabular-nums] ${
                                                    payment.status === 'failed'
                                                        ? 'text-muted-foreground line-through'
                                                        : ''
                                                }`}
                                            >
                                                {money(payment.amount)}
                                            </span>
                                            <StatusText status={payment.status} />
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>

                        <div className="flex items-center justify-between gap-3">
                            <p className="font-data text-xs text-muted-foreground tabular-nums">
                                {meta.from ?? 0}–{meta.to ?? 0} of {meta.total.toLocaleString('en-US')}
                            </p>

                            {meta.lastPage > 1 && (
                                <div className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        disabled={meta.page <= 1}
                                        onClick={() => go({ page: meta.page - 1 })}
                                        aria-label="Previous page"
                                    >
                                        <ChevronLeft className="size-4" />
                                    </Button>
                                    <span className="font-data px-2 text-xs tabular-nums text-muted-foreground">
                                        {meta.page} / {meta.lastPage}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        disabled={meta.page >= meta.lastPage}
                                        onClick={() => go({ page: meta.page + 1 })}
                                        aria-label="Next page"
                                    >
                                        <ChevronRight className="size-4" />
                                    </Button>
                                </div>
                            )}
                        </div>
                    </>
                )}
            </div>

            <Dialog open={open !== null} onOpenChange={(next) => !next && setOpen(null)}>
                <DialogContent>
                    {open && <Detail payment={open} money={money} />}
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}

function Figure({
    label,
    value,
    note,
    accent = false,
}: {
    label: string;
    value: string;
    note?: string;
    accent?: boolean;
}) {
    return (
        <div className="bg-card p-4 sm:p-5">
            <p className="text-xs text-muted-foreground sm:text-sm">{label}</p>
            <p
                className={`font-heading mt-1 truncate text-2xl font-extrabold tracking-tight sm:text-3xl ${
                    accent ? 'text-primary' : ''
                }`}
            >
                {value}
            </p>
            <p className="mt-1 min-h-4 truncate text-xs text-muted-foreground">{note}</p>
        </div>
    );
}

function Detail({ payment, money }: { payment: Row; money: (value: number) => string }) {
    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    {money(payment.amount)}
                    <StatusText status={payment.status} />
                </DialogTitle>
                <DialogDescription>
                    {typeLabel(payment.type)} · {payment.gatewayLabel}
                </DialogDescription>
            </DialogHeader>

            <dl className="space-y-3 text-sm">
                <Line label="Customer" value={customerLabel(payment)} />
                {payment.customer?.name && <Line label="Number" value={payment.customer.phone} mono />}
                <Line label="Date" value={fullDate(payment.createdAt)} />
                <Line label="Reference" value={payment.reference ?? '—'} mono />
                {payment.gatewayReference && (
                    <Line label="Gateway reference" value={payment.gatewayReference} mono />
                )}
                {payment.orderId && (
                    <Line
                        label="For order"
                        value={`#${payment.orderId}${payment.orderService ? ` · ${payment.orderService}` : ''}`}
                    />
                )}
            </dl>

            {payment.status === 'pending' && (
                <p className="rounded-xl bg-muted/60 p-3 text-xs text-muted-foreground">
                    Waiting for the gateway to confirm. The customer is credited the moment it does —
                    nothing needs doing here.
                </p>
            )}

            {payment.customer && (
                <Button variant="outline" asChild>
                    <Link href={route('customers.index', { q: payment.customer.phone })}>
                        <Wallet className="size-4" />
                        See this customer
                    </Link>
                </Button>
            )}
        </>
    );
}

function Line({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className="shrink-0 text-muted-foreground">{label}</dt>
            <dd className={`min-w-0 break-all text-right ${mono ? 'font-data text-xs' : ''}`}>{value}</dd>
        </div>
    );
}

function Empty({ filtered }: { filtered: boolean }) {
    return (
        <div className="rounded-2xl border border-dashed border-border px-6 py-14 text-center">
            <span className="mx-auto grid size-12 place-items-center rounded-full bg-muted">
                <Wallet className="size-5 text-muted-foreground" aria-hidden />
            </span>
            <p className="mt-3 font-semibold">
                {filtered ? 'No payments match those filters' : 'No payments yet'}
            </p>
            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                {filtered
                    ? 'Try a longer time range, or clear the search.'
                    : 'When a customer tops up or pays for an order, it shows here.'}
            </p>
            {!filtered && (
                <Button size="sm" variant="outline" className="mt-4" asChild>
                    <Link href={route('order-bot.gateways')}>Set up how customers pay</Link>
                </Button>
            )}
        </div>
    );
}

/** Status as an icon and a word, never colour alone. */
function StatusIcon({ status }: { status: Row['status'] }) {
    const Icon = status === 'success' ? CheckCircle2 : status === 'pending' ? Clock : XCircle;
    const color =
        status === 'success' ? '#0ca30c' : status === 'pending' ? '#fab219' : '#d03b3b';

    return (
        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-muted/60" aria-hidden>
            <Icon className="size-4" style={{ color }} />
        </span>
    );
}

function StatusText({ status }: { status: Row['status'] }) {
    const label = { success: 'Paid', pending: 'Pending', failed: 'Failed' }[status];
    const tone = {
        success: 'text-[#006300] dark:text-[#0ca30c]',
        pending: 'text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]',
        failed: 'text-destructive',
    }[status];

    return <span className={`text-xs font-medium ${tone}`}>{label}</span>;
}

function customerLabel(payment: Row): string {
    return payment.customer?.name || payment.customer?.phone || 'Unknown customer';
}

function typeLabel(type: string): string {
    return TYPE_LABELS[type] ?? type;
}

function shortDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function fullDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
