import { Button } from '@/components/ui/button';
import {
    CheckCircle2,
    Clock,
    History,
    Loader2,
    Minus,
    Plus,
    RotateCcw,
    ShoppingBag,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import { ReactNode, useEffect, useState } from 'react';
import { fullDate, price, PRICE_REASON, relativeTime } from './bits';
import {
    Overview,
    PriceLog,
    Pricing,
    ServiceOrder,
    ServiceStatus,
    Settings,
} from './types';

/**
 * The drawer's five tabs.
 *
 * The pricing tab carries the most weight: it is where a reseller decides what
 * they earn, so the effect of a change is shown before it is saved rather than
 * after.
 */

// ---- Overview ------------------------------------------------------------

export function OverviewTab({ data, onEdit }: { data: Overview; onEdit: () => void }) {
    return (
        <div className="space-y-6">
            {data.autoPaused && (
                <div className="flex items-start gap-2.5 rounded-xl bg-[oklch(0.77_0.16_70/0.12)] p-3 text-sm">
                    <TriangleAlert
                        className="mt-0.5 size-4 shrink-0 text-[oklch(0.55_0.13_70)]"
                        aria-hidden
                    />
                    <div>
                        <p className="font-semibold">Paused automatically</p>
                        <p className="mt-0.5 text-muted-foreground">
                            The panel stopped listing this service. It will go live again by
                            itself if the panel brings it back.
                        </p>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-3 gap-3">
                <Metric label="Orders" value={data.performance.orders.toLocaleString('en-US')} />
                <Metric label="Revenue" value={price(data.performance.revenue)} />
                <Metric
                    label="Profit taken"
                    value={
                        data.performance.profit === null ? '—' : price(data.performance.profit)
                    }
                    accent={data.performance.profit !== null}
                />
            </div>

            <Section title="Service">
                <Row label="Name" value={data.name} />
                <Row label="Platform" value={data.platform} />
                <Row label="Category" value={data.category ?? '—'} />
                <Row label="Unit" value={data.unitLabel} />
                {data.description && <Row label="Description" value={data.description} />}
            </Section>

            <Section title="Provider">
                <Row label="Panel" value={data.panel ?? 'None — catalogue only'} />
                <Row label="Panel service ID" value={data.providerServiceId ?? '—'} mono />
                <Row label="Last synced" value={fullDate(data.lastSyncedAt)} />
            </Section>

            <Section title="Limits">
                <Row
                    label="Minimum"
                    value={`${data.minQuantity.toLocaleString('en-US')} ${data.unitLabel}`}
                />
                <Row
                    label="Maximum"
                    value={`${data.maxQuantity.toLocaleString('en-US')} ${data.unitLabel}`}
                />
                {data.linkInstructions && (
                    <Row label="Link help" value={data.linkInstructions} />
                )}
            </Section>

            <Button variant="outline" size="sm" onClick={onEdit} className="w-full">
                Edit service
            </Button>
        </div>
    );
}

// ---- Pricing -------------------------------------------------------------

export function PricingTab({
    data,
    pending,
    onSave,
}: {
    data: Pricing;
    pending: boolean;
    onSave: (price: string) => void;
}) {
    const [draft, setDraft] = useState(data.price.toString());

    // Adopt the server's price when it changes underneath — after a save, or a
    // sync that repriced this service while the drawer was open.
    useEffect(() => setDraft(data.price.toString()), [data.price]);

    const parsed = Number.parseFloat(draft);
    const valid = Number.isFinite(parsed) && parsed > 0;
    const newProfit = valid && data.cost !== null ? parsed - data.cost : null;
    const newMargin = valid && newProfit !== null && parsed > 0 ? (newProfit / parsed) * 100 : null;
    const changed = valid && parsed !== data.price;

    const nudge = (factor: number) => {
        if (!valid) return;

        setDraft((parsed * factor).toFixed(4).replace(/0+$/, '').replace(/\.$/, ''));
    };

    return (
        <div className="space-y-5">
            {data.underwater && (
                <div className="flex items-start gap-2.5 rounded-xl bg-destructive/10 p-3 text-sm text-destructive">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                    <div>
                        <p className="font-semibold">Selling below cost</p>
                        <p className="mt-0.5 opacity-90">
                            Every order on this service loses you{' '}
                            {price(Math.abs(data.profit ?? 0))}.
                        </p>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-3 gap-3">
                <Metric label="Panel cost" value={price(data.cost)} />
                <Metric label="Your price" value={price(data.price)} />
                <Metric
                    label="Margin"
                    value={data.margin === null ? '—' : `${data.margin.toFixed(1)}%`}
                    accent={!data.underwater && data.margin !== null}
                />
            </div>

            <div className="rounded-xl border border-border p-3">
                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Set a price
                </p>
                <p className="mt-1 text-xs text-muted-foreground">Per 1,000 {' '}
                    units, the same basis the panel quotes on.
                </p>

                <div className="mt-2.5 flex gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        aria-label="Lower by 10%"
                        disabled={pending || !valid}
                        onClick={() => nudge(0.9)}
                    >
                        <Minus className="size-3.5" />
                        10%
                    </Button>

                    <input
                        type="number"
                        min="0"
                        step="0.0001"
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        aria-label="Price per 1,000"
                        className="font-data h-9 min-w-0 flex-1 rounded-lg border border-border bg-background px-3 text-center text-sm tabular-nums outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                    />

                    <Button
                        size="sm"
                        variant="outline"
                        aria-label="Raise by 10%"
                        disabled={pending || !valid}
                        onClick={() => nudge(1.1)}
                    >
                        <Plus className="size-3.5" />
                        10%
                    </Button>
                </div>

                {/* The calculator: what this price would mean, before saving. */}
                <div className="mt-3 grid grid-cols-2 gap-2 rounded-lg bg-muted/50 p-2.5 text-sm">
                    <span className="text-muted-foreground">New profit</span>
                    <span
                        className={`font-data text-right tabular-nums ${
                            newProfit !== null && newProfit < 0 ? 'text-destructive' : ''
                        }`}
                    >
                        {newProfit === null ? '—' : price(newProfit)}
                    </span>

                    <span className="text-muted-foreground">New margin</span>
                    <span
                        className={`font-data text-right tabular-nums ${
                            newMargin !== null && newMargin < 0 ? 'text-destructive' : ''
                        }`}
                    >
                        {newMargin === null ? '—' : `${newMargin.toFixed(1)}%`}
                    </span>
                </div>

                <div className="mt-2.5 flex gap-2">
                    <Button
                        size="sm"
                        variant="ghost"
                        className="flex-1"
                        disabled={pending || !changed}
                        onClick={() => setDraft(data.price.toString())}
                    >
                        <RotateCcw className="size-3.5" />
                        Reset
                    </Button>
                    <Button
                        size="sm"
                        className="flex-1"
                        disabled={pending || !changed}
                        onClick={() => onSave(parsed.toFixed(4))}
                    >
                        {pending && <Loader2 className="size-3.5 animate-spin" />}
                        Save price
                    </Button>
                </div>
            </div>

            <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Recent changes
                </p>
                <LogList entries={data.history.slice(0, 6)} />
            </div>
        </div>
    );
}

// ---- Orders --------------------------------------------------------------

const ORDER_STATUS: Record<
    ServiceOrder['status'],
    { label: string; className: string; icon: typeof CheckCircle2 }
> = {
    completed: { label: 'Completed', className: 'text-[oklch(0.55_0.14_150)]', icon: CheckCircle2 },
    processing: { label: 'In progress', className: 'text-[oklch(0.55_0.15_255)]', icon: Loader2 },
    pending: { label: 'Pending', className: 'text-[oklch(0.60_0.14_70)]', icon: Clock },
    failed: { label: 'Failed', className: 'text-destructive', icon: XCircle },
};

export function OrdersTab({ orders }: { orders: ServiceOrder[] }) {
    if (orders.length === 0) {
        return <Empty icon={ShoppingBag} text="No orders on this service yet." />;
    }

    return (
        <div className="space-y-2">
            {orders.map((order) => {
                const status = ORDER_STATUS[order.status];
                const Icon = status.icon;

                return (
                    <div key={order.id} className="rounded-xl border border-border p-3">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="font-data truncate text-sm">{order.customer}</p>
                                <p className="font-data mt-0.5 text-xs text-muted-foreground">
                                    #{order.id}
                                    {order.quantity !== null &&
                                        ` · ${order.quantity.toLocaleString('en-US')}`}
                                </p>
                            </div>
                            <div className="shrink-0 text-right">
                                <p className="font-data text-sm font-medium tabular-nums">
                                    {price(order.charge)}
                                </p>
                                {order.profit !== null && (
                                    <p className="font-data text-xs text-muted-foreground tabular-nums">
                                        +{price(order.profit)} profit
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="mt-2 flex items-center justify-between gap-2">
                            <span
                                className={`inline-flex items-center gap-1 text-xs font-medium ${status.className}`}
                                title={order.rawStatus ?? undefined}
                            >
                                <Icon className="size-3" aria-hidden />
                                {status.label}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {relativeTime(order.at)}
                            </span>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

// ---- Logs ----------------------------------------------------------------

export function LogsTab({ logs }: { logs: PriceLog[] }) {
    if (logs.length === 0) {
        return <Empty icon={History} text="No price changes recorded yet." />;
    }

    return <LogList entries={logs} />;
}

function LogList({ entries }: { entries: PriceLog[] }) {
    if (entries.length === 0) {
        return <p className="py-4 text-center text-xs text-muted-foreground">Nothing yet.</p>;
    }

    return (
        <ol className="space-y-1.5">
            {entries.map((entry) => {
                const rose =
                    entry.oldPrice !== null && entry.newPrice > entry.oldPrice;
                const priceMoved =
                    entry.oldPrice !== null && entry.newPrice !== entry.oldPrice;
                const costMoved =
                    entry.oldCost !== null &&
                    entry.newCost !== null &&
                    entry.newCost !== entry.oldCost;

                return (
                    <li
                        key={entry.id}
                        className="flex items-start justify-between gap-3 rounded-lg border border-border px-3 py-2"
                    >
                        <div className="min-w-0">
                            <p className="text-sm">
                                {PRICE_REASON[entry.reason] ?? entry.reason}
                            </p>
                            {entry.note && (
                                <p className="truncate text-xs text-muted-foreground">
                                    {entry.note}
                                </p>
                            )}
                            {/* A cost move with no price move is exactly when a
                                margin quietly shrinks — worth spelling out. */}
                            {costMoved && !priceMoved && (
                                <p className="text-xs text-muted-foreground">
                                    Cost {price(entry.oldCost)} → {price(entry.newCost)}, your
                                    price unchanged
                                </p>
                            )}
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {relativeTime(entry.at)}
                            </p>
                        </div>

                        <div className="shrink-0 text-right">
                            {priceMoved ? (
                                <p className="font-data text-sm tabular-nums">
                                    <span className="text-muted-foreground line-through">
                                        {price(entry.oldPrice)}
                                    </span>{' '}
                                    <span className={rose ? 'text-primary' : 'text-destructive'}>
                                        {price(entry.newPrice)}
                                    </span>
                                </p>
                            ) : (
                                <p className="font-data text-sm tabular-nums text-muted-foreground">
                                    {price(entry.newPrice)}
                                </p>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

// ---- Settings ------------------------------------------------------------

export function SettingsTab({
    data,
    pending,
    onStatus,
    onFlag,
}: {
    data: Settings;
    pending: boolean;
    onStatus: (status: ServiceStatus) => void;
    onFlag: (flag: 'featured' | 'requires_approval', value: boolean) => void;
}) {
    return (
        <div className="space-y-5">
            <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Availability
                </p>
                <div className="space-y-1.5">
                    <StatusOption
                        label="Active"
                        hint="Customers can see it and order it"
                        selected={data.status === 'active'}
                        disabled={pending}
                        onSelect={() => onStatus('active')}
                    />
                    <StatusOption
                        label="Paused"
                        hint="Visible, but the bot will not take new orders"
                        selected={data.status === 'paused'}
                        disabled={pending}
                        onSelect={() => onStatus('paused')}
                    />
                    <StatusOption
                        label="Hidden"
                        hint="Removed from the bot entirely"
                        selected={data.status === 'hidden'}
                        disabled={pending}
                        onSelect={() => onStatus('hidden')}
                    />
                </div>
            </div>

            <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Behaviour
                </p>
                <div className="space-y-1.5">
                    <Toggle
                        label="Featured"
                        hint="Shown first in the bot's list"
                        checked={data.featured}
                        disabled={pending}
                        onChange={(value) => onFlag('featured', value)}
                    />
                    <Toggle
                        label="Needs your approval"
                        hint="Hold orders for review before the panel is charged"
                        checked={data.requiresApproval}
                        disabled={pending}
                        onChange={(value) => onFlag('requires_approval', value)}
                    />
                </div>
            </div>
        </div>
    );
}

function StatusOption({
    label,
    hint,
    selected,
    disabled,
    onSelect,
}: {
    label: string;
    hint: string;
    selected: boolean;
    disabled: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            disabled={disabled || selected}
            onClick={onSelect}
            aria-pressed={selected}
            className={[
                'flex w-full items-start gap-2.5 rounded-lg border p-2.5 text-left transition-colors',
                selected
                    ? 'border-primary/40 bg-primary/5'
                    : 'border-border hover:bg-accent/50 disabled:opacity-50',
            ].join(' ')}
        >
            <span
                className={`mt-0.5 size-3.5 shrink-0 rounded-full border-2 ${
                    selected ? 'border-primary bg-primary' : 'border-border'
                }`}
                aria-hidden
            />
            <span className="min-w-0">
                <span className="block text-sm font-medium">{label}</span>
                <span className="block text-xs text-muted-foreground">{hint}</span>
            </span>
        </button>
    );
}

function Toggle({
    label,
    hint,
    checked,
    disabled,
    onChange,
}: {
    label: string;
    hint: string;
    checked: boolean;
    disabled: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <label className="flex items-start gap-2.5 rounded-lg border border-border p-2.5">
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-0.5 size-4 shrink-0 cursor-pointer rounded border-border accent-primary"
            />
            <span className="min-w-0">
                <span className="block text-sm font-medium">{label}</span>
                <span className="block text-xs text-muted-foreground">{hint}</span>
            </span>
        </label>
    );
}

// ---- Shared --------------------------------------------------------------

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {title}
            </h3>
            <dl className="space-y-2">{children}</dl>
        </section>
    );
}

function Row({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: ReactNode;
    mono?: boolean;
}) {
    return (
        <div className="grid grid-cols-[minmax(0,8rem)_1fr] gap-3 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className={`min-w-0 break-words ${mono ? 'font-data text-[0.82rem]' : ''}`}>
                {value}
            </dd>
        </div>
    );
}

function Metric({
    label,
    value,
    accent = false,
}: {
    label: string;
    value: string;
    accent?: boolean;
}) {
    return (
        <div className="rounded-xl border border-border p-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={`font-data mt-1 truncate text-lg font-semibold tabular-nums tracking-tight ${
                    accent ? 'text-primary' : ''
                }`}
            >
                {value}
            </p>
        </div>
    );
}

function Empty({ icon: Icon, text }: { icon: typeof ShoppingBag; text: string }) {
    return (
        <div className="py-10 text-center">
            <Icon className="mx-auto size-6 text-muted-foreground/60" aria-hidden />
            <p className="mt-2 text-sm text-muted-foreground">{text}</p>
        </div>
    );
}

export function TabSkeleton() {
    return (
        <div className="space-y-3" aria-hidden>
            <div className="grid grid-cols-3 gap-3">
                {[0, 1, 2].map((index) => (
                    <div key={index} className="h-[4.5rem] animate-pulse rounded-xl bg-muted" />
                ))}
            </div>
            {[0, 1, 2, 3].map((index) => (
                <div key={index} className="h-4 w-full animate-pulse rounded bg-muted" />
            ))}
            <div className="h-4 w-2/3 animate-pulse rounded bg-muted" />
        </div>
    );
}
