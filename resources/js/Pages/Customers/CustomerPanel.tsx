import { Button } from '@/components/ui/button';
import { Dialog } from 'radix-ui';
import { Ban, Loader2, ShieldCheck, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Avatar, prettyPhone, relativeTime } from './bits';
import {
    ActivityTab,
    MessagesTab,
    OrdersTab,
    OverviewTab,
    TabSkeleton,
    TicketsTab,
    WalletTab,
} from './tabs';
import { CustomerRow, TabKey, TabPayload } from './types';

const TABS: Array<{ key: TabKey; label: string }> = [
    { key: 'overview', label: 'Overview' },
    { key: 'orders', label: 'Orders' },
    { key: 'messages', label: 'Messages' },
    { key: 'wallet', label: 'Wallet' },
    { key: 'tickets', label: 'Tickets' },
    { key: 'activity', label: 'Activity' },
];

/**
 * One customer, opened over the list rather than on their own page.
 *
 * Each tab is fetched the first time it is opened and then kept, so switching
 * back and forth is instant while opening a customer still costs one request.
 * The cache is keyed by customer, so a different row starts clean — showing
 * one person's orders under another's name would be worse than a spinner.
 */
export default function CustomerPanel({
    customer,
    pending,
    onClose,
    onAction,
    onEdit,
    onDelete,
}: {
    customer: CustomerRow | null;
    pending: boolean;
    onClose: () => void;
    onAction: (
        customer: CustomerRow,
        action: 'wallet' | 'block' | 'unblock' | 'message',
        payload?: { amount?: string; reason?: string; text?: string },
    ) => void;
    onEdit: (customer: CustomerRow) => void;
    onDelete: (customer: CustomerRow) => void;
}) {
    const [tab, setTab] = useState<TabKey>('overview');
    const [cache, setCache] = useState<Partial<Record<TabKey, TabPayload>>>({});
    const [loading, setLoading] = useState(false);

    const customerId = customer?.id ?? null;

    // A new customer resets everything — tab, cache and all.
    useEffect(() => {
        setTab('overview');
        setCache({});
    }, [customerId]);

    const load = useCallback(
        async (which: TabKey, force = false) => {
            if (customerId === null || (!force && cache[which])) {
                return;
            }

            setLoading(true);

            try {
                const response = await fetch(route('customers.show', [customerId, which]), {
                    headers: { Accept: 'application/json' },
                });

                if (response.ok) {
                    const payload: TabPayload = await response.json();

                    setCache((current) => ({ ...current, [which]: payload }));
                }
            } finally {
                setLoading(false);
            }
        },
        [customerId, cache],
    );

    useEffect(() => {
        if (customerId !== null) {
            void load(tab);
        }
    }, [customerId, tab, load]);

    /**
     * After an action changes something, the tab showing it is stale. Rather
     * than re-fetching all six, only the affected ones are dropped.
     */
    const invalidate = useCallback((keys: TabKey[]) => {
        setCache((current) => {
            const next = { ...current };
            keys.forEach((key) => delete next[key]);

            return next;
        });
    }, []);

    const data = cache[tab];

    return (
        <Dialog.Root open={customer !== null} onOpenChange={(open) => !open && onClose()}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-50 bg-foreground/20 backdrop-blur-[1px] data-[state=closed]:animate-out data-[state=closed]:fade-out data-[state=open]:animate-in data-[state=open]:fade-in" />

                <Dialog.Content
                    className="fixed inset-y-0 right-0 z-50 flex w-full max-w-xl flex-col border-l border-border bg-background shadow-2xl duration-200 data-[state=closed]:animate-out data-[state=closed]:slide-out-to-right data-[state=open]:animate-in data-[state=open]:slide-in-from-right"
                    aria-describedby={undefined}
                >
                    {customer === null ? null : (
                        <>
                            <header className="border-b border-border px-6 pb-3 pt-5">
                                <div className="flex items-start gap-3">
                                    <Avatar
                                        name={customer.name}
                                        phone={customer.phone}
                                        size="lg"
                                    />

                                    <div className="min-w-0 flex-1">
                                        <Dialog.Title className="font-heading truncate text-lg font-extrabold tracking-tight">
                                            {customer.name ?? prettyPhone(customer.phone)}
                                        </Dialog.Title>
                                        <p className="font-data mt-0.5 truncate text-sm text-muted-foreground">
                                            {prettyPhone(customer.phone)}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Last seen {relativeTime(customer.lastSeenAt)}
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
                                </div>

                                <nav
                                    className="-mb-3 mt-3 flex gap-1 overflow-x-auto"
                                    aria-label="Customer details"
                                >
                                    {TABS.map((item) => (
                                        <button
                                            key={item.key}
                                            type="button"
                                            onClick={() => setTab(item.key)}
                                            aria-current={tab === item.key ? 'true' : undefined}
                                            className={[
                                                'shrink-0 border-b-2 px-2.5 py-2 text-sm transition-colors',
                                                tab === item.key
                                                    ? 'border-primary font-semibold text-foreground'
                                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                                            ].join(' ')}
                                        >
                                            {item.label}
                                        </button>
                                    ))}
                                </nav>
                            </header>

                            <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                                {loading && !data ? (
                                    <TabSkeleton />
                                ) : (
                                    <>
                                        {tab === 'overview' && data?.overview && (
                                            <OverviewTab
                                                data={data.overview}
                                                onEdit={() => onEdit(customer)}
                                            />
                                        )}
                                        {tab === 'orders' && data?.orders && (
                                            <OrdersTab orders={data.orders} />
                                        )}
                                        {tab === 'messages' && data?.messages && (
                                            <MessagesTab
                                                messages={data.messages}
                                                canReply={data.canReply ?? false}
                                                windowClosesAt={data.windowClosesAt ?? null}
                                                sending={pending}
                                                onSend={(text) => {
                                                    onAction(customer, 'message', { text });
                                                    invalidate(['messages', 'activity']);
                                                }}
                                            />
                                        )}
                                        {tab === 'wallet' && data?.wallet && (
                                            <WalletTab
                                                wallet={data.wallet}
                                                pending={pending}
                                                onAdjust={(amount, reason) => {
                                                    onAction(customer, 'wallet', {
                                                        amount,
                                                        reason,
                                                    });
                                                    invalidate([
                                                        'wallet',
                                                        'overview',
                                                        'activity',
                                                    ]);
                                                }}
                                            />
                                        )}
                                        {tab === 'tickets' && data?.tickets && (
                                            <TicketsTab tickets={data.tickets} />
                                        )}
                                        {tab === 'activity' && data?.activity && (
                                            <ActivityTab activity={data.activity} />
                                        )}
                                    </>
                                )}
                            </div>

                            <footer className="flex flex-wrap items-center gap-2 border-t border-border px-6 py-3">
                                {customer.blocked ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={pending}
                                        onClick={() => {
                                            onAction(customer, 'unblock');
                                            invalidate(['overview', 'activity']);
                                        }}
                                    >
                                        <ShieldCheck className="size-3.5" />
                                        Unblock
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={pending}
                                        onClick={() => {
                                            onAction(customer, 'block');
                                            invalidate(['overview', 'activity']);
                                        }}
                                    >
                                        <Ban className="size-3.5" />
                                        Block
                                    </Button>
                                )}

                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={pending}
                                    onClick={() => onDelete(customer)}
                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                >
                                    <Trash2 className="size-3.5" />
                                    Delete
                                </Button>

                                {pending && (
                                    <Loader2
                                        className="ms-auto size-4 animate-spin text-muted-foreground"
                                        aria-hidden
                                    />
                                )}
                            </footer>
                        </>
                    )}
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
