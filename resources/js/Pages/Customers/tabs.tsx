import { Button } from '@/components/ui/button';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Ban,
    Bot,
    CheckCircle2,
    Clock,
    Inbox,
    LifeBuoy,
    Loader2,
    MessageSquare,
    Send,
    ShoppingBag,
    Ticket as TicketIcon,
    UserPlus,
    Wallet as WalletIcon,
    XCircle,
} from 'lucide-react';
import { FormEvent, ReactNode, useState } from 'react';
import { fullDate, money, prettyPhone, relativeTime, SegmentChips } from './bits';
import {
    Overview,
    ProfileActivity,
    ProfileMessage,
    ProfileOrder,
    ProfileTicket,
    ProfileWallet,
} from './types';

/**
 * The contents of the slide-over's six tabs.
 *
 * Each renders only what the server sent for that tab, so a tab the reseller
 * never opens costs nothing. The empty states are specific — "no orders yet"
 * and "no tickets yet" mean different things, and a shared "no data" would
 * tell them neither.
 */

// ---- Overview ------------------------------------------------------------

export function OverviewTab({
    data,
    onEdit,
}: {
    data: Overview;
    onEdit: () => void;
}) {
    return (
        <div className="space-y-6">
            {data.blocked && (
                <div className="flex items-start gap-2.5 rounded-xl bg-destructive/10 p-3 text-sm text-destructive">
                    <Ban className="mt-0.5 size-4 shrink-0" aria-hidden />
                    <div>
                        <p className="font-semibold">Blocked</p>
                        <p className="mt-0.5 opacity-90">
                            The bot stopped replying to this number
                            {data.blockedAt ? ` on ${fullDate(data.blockedAt)}` : ''}.
                        </p>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-3 gap-3">
                <Metric label="Orders" value={data.orders.total.toLocaleString('en-US')} />
                <Metric label="Spent" value={money(data.spent)} />
                <Metric label="Wallet" value={money(data.balance)} accent />
            </div>

            <Section title="Contact">
                <Row label="Phone" value={prettyPhone(data.phone)} mono />
                <Row label="Email" value={data.email ?? '—'} />
                <Row label="Country" value={data.country ?? '—'} />
                <Row label="Language" value={data.lang.toUpperCase()} />
            </Section>

            <Section title="Orders">
                <Row label="Completed" value={data.orders.completed.toLocaleString('en-US')} />
                <Row label="Cancelled" value={data.orders.cancelled.toLocaleString('en-US')} />
                <Row
                    label="Pays with"
                    value={data.preferredPayment ?? 'No successful payment yet'}
                    capitalize={data.preferredPayment !== null}
                />
            </Section>

            <Section title="Referrals">
                <Row label="Their code" value={data.referral.code ?? '—'} mono />
                <Row label="Invited" value={`${data.referral.invited} customers`} />
                <Row label="Earned" value={money(data.referral.earnings)} />
                <Row
                    label="Invited by"
                    value={
                        data.referral.invitedBy
                            ? (data.referral.invitedBy.name ??
                              prettyPhone(data.referral.invitedBy.phone))
                            : '—'
                    }
                />
            </Section>

            <Section title="History">
                <Row label="First seen" value={fullDate(data.joinedAt)} />
                <Row label="Last message" value={fullDate(data.lastSeenAt)} />
            </Section>

            {data.tags.length > 0 && (
                <Section title="Tags">
                    <div className="flex flex-wrap gap-1.5">
                        {data.tags.map((tag) => (
                            <span
                                key={tag}
                                className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                            >
                                {tag}
                            </span>
                        ))}
                    </div>
                </Section>
            )}

            {data.notes && (
                <Section title="Notes">
                    <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                        {data.notes}
                    </p>
                </Section>
            )}

            <div className="flex flex-wrap gap-2 pt-1">
                <SegmentChips segments={data.segments} />
            </div>

            <Button variant="outline" size="sm" onClick={onEdit} className="w-full">
                Edit details
            </Button>
        </div>
    );
}

// ---- Orders --------------------------------------------------------------

const ORDER_STATUS: Record<
    ProfileOrder['status'],
    { label: string; className: string; icon: typeof CheckCircle2 }
> = {
    completed: {
        label: 'Completed',
        className: 'text-[oklch(0.55_0.14_150)]',
        icon: CheckCircle2,
    },
    processing: { label: 'In progress', className: 'text-[oklch(0.55_0.15_255)]', icon: Loader2 },
    pending: { label: 'Pending', className: 'text-[oklch(0.60_0.14_70)]', icon: Clock },
    failed: { label: 'Failed', className: 'text-destructive', icon: XCircle },
};

export function OrdersTab({ orders }: { orders: ProfileOrder[] }) {
    if (orders.length === 0) {
        return <Empty icon={ShoppingBag} text="No orders yet." />;
    }

    return (
        <div className="space-y-2">
            {orders.map((order) => {
                const status = ORDER_STATUS[order.status];
                const Icon = status.icon;

                return (
                    <div
                        key={order.id}
                        className="rounded-xl border border-border p-3 transition-colors hover:border-foreground/15"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium">
                                    {order.service ?? 'Order'}
                                </p>
                                <p className="font-data mt-0.5 text-xs text-muted-foreground">
                                    #{order.id}
                                    {order.quantity !== null &&
                                        ` · ${order.quantity.toLocaleString('en-US')}`}
                                </p>
                            </div>

                            <div className="shrink-0 text-right">
                                <p className="font-data text-sm font-medium tabular-nums">
                                    {order.amount !== null ? money(order.amount) : '—'}
                                </p>
                                {/* Profit is hidden rather than shown as zero when
                                    the panel has not reported a charge — an unknown
                                    margin displayed as 0.00 reads as "made nothing". */}
                                {order.profit !== null && (
                                    <p className="font-data text-xs text-muted-foreground tabular-nums">
                                        +{money(order.profit)} profit
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

// ---- Messages ------------------------------------------------------------

export function MessagesTab({
    messages,
    canReply,
    windowClosesAt,
    sending,
    onSend,
}: {
    messages: ProfileMessage[];
    canReply: boolean;
    windowClosesAt: string | null;
    sending: boolean;
    onSend: (text: string) => void;
}) {
    const [draft, setDraft] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (draft.trim() === '' || sending) {
            return;
        }

        onSend(draft.trim());
        setDraft('');
    };

    return (
        <div className="flex h-full flex-col">
            <div className="min-h-0 flex-1 space-y-2 overflow-y-auto">
                {messages.length === 0 ? (
                    <Empty icon={MessageSquare} text="No messages yet." />
                ) : (
                    messages.map((message) => (
                        <Bubble key={message.id} message={message} />
                    ))
                )}
            </div>

            <form onSubmit={submit} className="mt-3 border-t border-border pt-3">
                {canReply ? (
                    <>
                        <div className="flex gap-2">
                            <input
                                value={draft}
                                onChange={(event) => setDraft(event.target.value)}
                                placeholder="Write a reply…"
                                disabled={sending}
                                aria-label="Reply to this customer"
                                className="h-9 min-w-0 flex-1 rounded-lg border border-border bg-background px-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                            />
                            <Button type="submit" size="sm" disabled={sending || draft.trim() === ''}>
                                {sending ? (
                                    <Loader2 className="size-3.5 animate-spin" />
                                ) : (
                                    <Send className="size-3.5" />
                                )}
                                Send
                            </Button>
                        </div>
                        {windowClosesAt && (
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                You can reply freely until {fullDate(windowClosesAt)}.
                            </p>
                        )}
                    </>
                ) : (
                    // Said plainly and up front. Meta refuses free-form messages
                    // outside the window, and a disabled box with no reason is
                    // how a reseller ends up thinking the feature is broken.
                    <p className="flex items-start gap-2 rounded-lg bg-muted p-2.5 text-xs text-muted-foreground">
                        <Clock className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                        WhatsApp only allows a free reply within 24 hours of the customer's
                        last message. Wait for them to write in again.
                    </p>
                )}
            </form>
        </div>
    );
}

function Bubble({ message }: { message: ProfileMessage }) {
    const inbound = message.direction === 'in';

    return (
        <div className={`flex ${inbound ? 'justify-start' : 'justify-end'}`}>
            <div
                className={[
                    'max-w-[85%] rounded-xl px-3 py-2',
                    inbound ? 'bg-muted' : 'bg-primary/10',
                ].join(' ')}
            >
                <p className="whitespace-pre-wrap break-words text-sm">
                    {message.text || <span className="italic opacity-60">(no text)</span>}
                </p>
                <p className="mt-1 flex items-center gap-1.5 text-[0.7rem] text-muted-foreground">
                    {!inbound && <Bot className="size-3" aria-hidden />}
                    {message.bot === 'support' && inbound && (
                        <LifeBuoy className="size-3" aria-hidden />
                    )}
                    {relativeTime(message.at)}
                </p>
            </div>
        </div>
    );
}

// ---- Wallet --------------------------------------------------------------

export function WalletTab({
    wallet,
    pending,
    onAdjust,
}: {
    wallet: ProfileWallet;
    pending: boolean;
    onAdjust: (amount: string, reason: string) => void;
}) {
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');

    const apply = (sign: 1 | -1) => {
        const value = parseFloat(amount);

        if (!Number.isFinite(value) || value <= 0) {
            return;
        }

        onAdjust((sign * value).toFixed(2), reason.trim());
        setAmount('');
        setReason('');
    };

    return (
        <div className="space-y-5">
            <div className="rounded-xl border border-border p-4">
                <p className="text-xs text-muted-foreground">Wallet balance</p>
                <p className="font-data mt-1 text-3xl font-semibold tabular-nums tracking-tight">
                    {money(wallet.balance)}
                </p>
                <div className="mt-3 flex gap-4 border-t border-border pt-3 text-xs text-muted-foreground">
                    <span>
                        Spent{' '}
                        <span className="font-data font-medium text-foreground tabular-nums">
                            {money(wallet.spent)}
                        </span>
                    </span>
                    <span>
                        Referral earnings{' '}
                        <span className="font-data font-medium text-foreground tabular-nums">
                            {money(wallet.referralEarnings)}
                        </span>
                    </span>
                </div>
            </div>

            <div className="rounded-xl border border-border p-3">
                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Adjust by hand
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    For money taken outside the bot. Every adjustment is recorded below.
                </p>

                <div className="mt-2.5 space-y-2">
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={amount}
                        onChange={(event) => setAmount(event.target.value)}
                        placeholder="Amount"
                        aria-label="Amount"
                        className="h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                    />
                    <input
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="Reason (optional)"
                        aria-label="Reason"
                        className="h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                    />
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            className="flex-1"
                            disabled={pending || amount === ''}
                            onClick={() => apply(1)}
                        >
                            <ArrowDownLeft className="size-3.5" />
                            Add
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            className="flex-1"
                            disabled={pending || amount === ''}
                            onClick={() => apply(-1)}
                        >
                            <ArrowUpRight className="size-3.5" />
                            Deduct
                        </Button>
                    </div>
                </div>
            </div>

            <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Transactions
                </p>

                {wallet.transactions.length === 0 ? (
                    <Empty icon={WalletIcon} text="No transactions yet." />
                ) : (
                    <div className="space-y-1.5">
                        {wallet.transactions.map((transaction) => (
                            <div
                                key={transaction.id}
                                className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-sm capitalize">
                                        {transaction.gateway === 'manual'
                                            ? 'Adjusted by hand'
                                            : transaction.gateway}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {relativeTime(transaction.at)}
                                        {transaction.status !== 'success' &&
                                            ` · ${transaction.status}`}
                                    </p>
                                </div>
                                <span
                                    className={`font-data shrink-0 text-sm font-medium tabular-nums ${
                                        transaction.amount < 0
                                            ? 'text-destructive'
                                            : 'text-foreground'
                                    }`}
                                >
                                    {transaction.amount >= 0 ? '+' : ''}
                                    {money(transaction.amount)}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

// ---- Tickets -------------------------------------------------------------

const TICKET_TONE: Record<string, string> = {
    open: 'bg-[oklch(0.60_0.14_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.80_0.14_70)]',
    pending: 'bg-muted text-muted-foreground',
    resolved: 'bg-primary/10 text-primary',
    closed: 'bg-muted text-muted-foreground',
};

export function TicketsTab({ tickets }: { tickets: ProfileTicket[] }) {
    if (tickets.length === 0) {
        return <Empty icon={TicketIcon} text="No support tickets." />;
    }

    return (
        <div className="space-y-2">
            {tickets.map((ticket) => (
                <div key={ticket.id} className="rounded-xl border border-border p-3">
                    <div className="flex items-start justify-between gap-3">
                        <p className="min-w-0 flex-1 text-sm font-medium">
                            {ticket.subject ?? ticket.subcategory ?? `Ticket #${ticket.id}`}
                        </p>
                        <span
                            className={`shrink-0 rounded-full px-2 py-0.5 text-[0.7rem] font-medium capitalize ${
                                TICKET_TONE[ticket.status] ?? 'bg-muted text-muted-foreground'
                            }`}
                        >
                            {ticket.status}
                        </span>
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {ticket.category === 'ai' ? 'Answered by AI' : 'Needs a person'}
                        {ticket.orderRef && ` · order ${ticket.orderRef}`} ·{' '}
                        {relativeTime(ticket.at)}
                    </p>
                </div>
            ))}
        </div>
    );
}

// ---- Activity ------------------------------------------------------------

const ACTIVITY_ICON: Record<string, typeof ShoppingBag> = {
    joined: UserPlus,
    order: ShoppingBag,
    payment: WalletIcon,
    payment_pending: Clock,
    ticket: LifeBuoy,
    blocked: Ban,
};

export function ActivityTab({ activity }: { activity: ProfileActivity[] }) {
    if (activity.length === 0) {
        return <Empty icon={Clock} text="Nothing has happened yet." />;
    }

    return (
        <ol className="relative space-y-4 pl-6">
            {/* One continuous rule behind the markers, rather than a border on
                each item — the line should not break between entries. */}
            <span
                className="absolute left-[0.6875rem] top-1.5 bottom-1.5 w-px bg-border"
                aria-hidden
            />

            {activity.map((event, index) => {
                const Icon = ACTIVITY_ICON[event.type] ?? Clock;

                return (
                    <li key={`${event.type}-${event.at}-${index}`} className="relative">
                        <span
                            className="absolute -left-6 top-0.5 flex size-[1.375rem] items-center justify-center rounded-full border border-border bg-background"
                            aria-hidden
                        >
                            <Icon className="size-3 text-muted-foreground" />
                        </span>

                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="text-sm font-medium">{event.title}</p>
                                {event.detail && (
                                    <p className="truncate text-xs text-muted-foreground">
                                        {event.detail}
                                    </p>
                                )}
                            </div>
                            {event.amount != null && (
                                <span className="font-data shrink-0 text-sm tabular-nums">
                                    {money(event.amount)}
                                </span>
                            )}
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {relativeTime(event.at)}
                        </p>
                    </li>
                );
            })}
        </ol>
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
    capitalize = false,
}: {
    label: string;
    value: ReactNode;
    mono?: boolean;
    capitalize?: boolean;
}) {
    return (
        <div className="grid grid-cols-[minmax(0,7rem)_1fr] gap-3 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={[
                    'min-w-0 break-words',
                    mono ? 'font-data text-[0.82rem]' : '',
                    capitalize ? 'capitalize' : '',
                ].join(' ')}
            >
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
                className={`font-data mt-1 text-lg font-semibold tabular-nums tracking-tight ${
                    accent ? 'text-primary' : ''
                }`}
            >
                {value}
            </p>
        </div>
    );
}

function Empty({ icon: Icon, text }: { icon: typeof Inbox; text: string }) {
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
