import { formatMoney } from '@/components/charts/chart-tokens';
import { Button } from '@/components/ui/button';
import { Dialog } from 'radix-ui';
import {
    ArrowUpRight,
    Ban,
    ExternalLink,
    RefreshCw,
    RotateCcw,
    TriangleAlert,
    X,
} from 'lucide-react';
import { ReactNode } from 'react';
import StatusBadge, { PaymentBadge } from './StatusBadge';
import { OrderAction, OrderRow, OrderStatusGroup } from './types';

/**
 * Order detail as a slide-over rather than its own page.
 *
 * A reseller checking why an order failed is working through a list — sending
 * them to /orders/912 and back loses their filter, their scroll position and
 * their selection every time. The panel keeps the table underneath alive.
 */
export default function OrderDetails({
    order,
    statusLabels,
    onClose,
    onAction,
    pending,
}: {
    order: OrderRow | null;
    statusLabels: Record<OrderStatusGroup, string>;
    onClose: () => void;
    onAction: (order: OrderRow, action: OrderAction, status?: OrderStatusGroup) => void;
    pending: boolean;
}) {
    return (
        <Dialog.Root open={order !== null} onOpenChange={(open) => !open && onClose()}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-50 bg-foreground/20 backdrop-blur-[1px] data-[state=closed]:animate-out data-[state=closed]:fade-out data-[state=open]:animate-in data-[state=open]:fade-in" />

                <Dialog.Content
                    className="fixed inset-y-0 right-0 z-50 flex w-full max-w-lg flex-col border-l border-border bg-background shadow-xl duration-200 data-[state=closed]:animate-out data-[state=closed]:slide-out-to-right data-[state=open]:animate-in data-[state=open]:slide-in-from-right"
                    aria-describedby={undefined}
                >
                    {order === null ? null : (
                        <>
                            <header className="flex items-start justify-between gap-4 border-b border-border px-6 py-5">
                                <div className="min-w-0">
                                    <Dialog.Title className="font-heading text-lg font-extrabold tracking-tight">
                                        Order #{order.id}
                                    </Dialog.Title>
                                    <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                        <StatusBadge
                                            status={order.status}
                                            label={statusLabels[order.status]}
                                            title={order.rawStatus}
                                        />
                                        <PaymentBadge status={order.paymentStatus} />
                                    </p>
                                </div>

                                <Dialog.Close asChild>
                                    <button
                                        type="button"
                                        className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-accent"
                                        aria-label="Close"
                                    >
                                        <X className="size-4" />
                                    </button>
                                </Dialog.Close>
                            </header>

                            <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                                {order.error && (
                                    <div className="mb-5 flex gap-2.5 rounded-lg bg-[#d03b3b]/10 p-3 text-sm text-[#d03b3b]">
                                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                        <div className="min-w-0">
                                            <p className="font-semibold">
                                                The panel rejected this order
                                            </p>
                                            <p className="mt-0.5 break-words opacity-90">
                                                {order.error}
                                            </p>
                                        </div>
                                    </div>
                                )}

                                <Section title="Service">
                                    <Row label="Name" value={order.service ?? '—'} />
                                    <Row
                                        label="Quantity"
                                        value={
                                            order.quantity !== null
                                                ? order.quantity.toLocaleString('en-US')
                                                : '—'
                                        }
                                    />
                                    <Row
                                        label="Link"
                                        value={
                                            order.link ? (
                                                <a
                                                    href={order.link}
                                                    target="_blank"
                                                    rel="noopener noreferrer nofollow"
                                                    className="inline-flex items-center gap-1 break-all text-primary hover:underline"
                                                >
                                                    {order.link}
                                                    <ExternalLink className="size-3 shrink-0" />
                                                </a>
                                            ) : (
                                                '—'
                                            )
                                        }
                                    />
                                </Section>

                                <Section title="Money">
                                    <Row
                                        label="Customer paid"
                                        value={
                                            order.amount !== null
                                                ? formatMoney(order.amount)
                                                : '—'
                                        }
                                    />
                                    <Row
                                        label="Panel charged you"
                                        value={
                                            order.charge !== null
                                                ? formatMoney(order.charge)
                                                : '—'
                                        }
                                    />
                                    {order.amount !== null && order.charge !== null && (
                                        <Row
                                            label="Your profit"
                                            value={
                                                <span className="font-semibold">
                                                    {formatMoney(
                                                        order.amount - order.charge,
                                                    )}
                                                </span>
                                            }
                                        />
                                    )}
                                    <Row
                                        label="Paid from"
                                        value={order.paidFrom ?? '—'}
                                        capitalize
                                    />
                                </Section>

                                <Section title="Customer">
                                    <Row label="Phone" value={order.customer} mono />
                                </Section>

                                <Section title="Fulfilment">
                                    <Row label="Panel" value={order.panel ?? '—'} />
                                    <Row
                                        label="Panel order ID"
                                        value={order.providerOrderId ?? 'Not submitted'}
                                        mono
                                    />
                                    <Row
                                        label="Panel status"
                                        value={order.rawStatus ?? '—'}
                                    />
                                    {order.refillStatus && (
                                        <Row label="Refill" value={order.refillStatus} />
                                    )}
                                    <Row label="Placed" value={formatFull(order.createdAt)} />
                                    <Row
                                        label="Last change"
                                        value={formatFull(order.updatedAt)}
                                    />
                                </Section>
                            </div>

                            <footer className="border-t border-border px-6 py-4">
                                <div className="flex flex-wrap gap-2">
                                    {order.actions.includes('retry') && (
                                        <Button
                                            size="sm"
                                            disabled={pending}
                                            onClick={() => onAction(order, 'retry')}
                                        >
                                            <RotateCcw className="size-3.5" />
                                            Send to panel again
                                        </Button>
                                    )}
                                    {order.actions.includes('refill') && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={pending}
                                            onClick={() => onAction(order, 'refill')}
                                        >
                                            <RefreshCw className="size-3.5" />
                                            Request refill
                                        </Button>
                                    )}
                                    {order.actions.includes('cancel') && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={pending}
                                            onClick={() => onAction(order, 'cancel')}
                                        >
                                            <Ban className="size-3.5" />
                                            Cancel on panel
                                        </Button>
                                    )}
                                </div>

                                <div className="mt-4 border-t border-border pt-3">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Set the status by hand
                                    </p>
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {(
                                            [
                                                'pending',
                                                'processing',
                                                'completed',
                                                'failed',
                                            ] as OrderStatusGroup[]
                                        ).map((group) => (
                                            <button
                                                key={group}
                                                type="button"
                                                disabled={pending || order.status === group}
                                                onClick={() =>
                                                    onAction(order, 'mark', group)
                                                }
                                                className="rounded-lg border border-border px-2.5 py-1 text-xs transition-colors hover:bg-accent disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                {statusLabels[group]}
                                            </button>
                                        ))}
                                    </div>
                                    {/* Said plainly rather than discovered later: the
                                        status sync will win on any order the panel
                                        knows about. */}
                                    {order.providerOrderId !== null && (
                                        <p className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
                                            <ArrowUpRight className="mt-0.5 size-3 shrink-0" />
                                            The panel is tracking this order, so the next
                                            status sync will overwrite whatever you set
                                            here.
                                        </p>
                                    )}
                                </div>
                            </footer>
                        </>
                    )}
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="mb-6 last:mb-0">
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
    capitalize = false,
}: {
    label: string;
    value: ReactNode;
    mono?: boolean;
    capitalize?: boolean;
}) {
    return (
        <div className="grid grid-cols-[minmax(0,7.5rem)_1fr] gap-3 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={[
                    'min-w-0 break-words',
                    mono ? 'font-mono text-[0.8rem]' : '',
                    capitalize ? 'capitalize' : '',
                ].join(' ')}
            >
                {value}
            </dd>
        </div>
    );
}

function formatFull(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
