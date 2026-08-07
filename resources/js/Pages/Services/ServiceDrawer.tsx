import { Button } from '@/components/ui/button';
import { Copy, Loader2, Trash2, X } from 'lucide-react';
import { Dialog } from 'radix-ui';
import { useCallback, useEffect, useState } from 'react';
import { price, StatusBadge } from './bits';
import {
    LogsTab,
    OrdersTab,
    OverviewTab,
    PricingTab,
    SettingsTab,
    TabSkeleton,
} from './tabs';
import { ServiceRow, ServiceStatus, TabKey, TabPayload } from './types';

const TABS: Array<{ key: TabKey; label: string }> = [
    { key: 'overview', label: 'Overview' },
    { key: 'pricing', label: 'Pricing' },
    { key: 'orders', label: 'Orders' },
    { key: 'logs', label: 'Logs' },
    { key: 'settings', label: 'Settings' },
];

/**
 * One service, opened over the list.
 *
 * Each tab is fetched the first time it is opened and then kept, so switching
 * back and forth is instant. The cache is keyed by service — showing one
 * service's price history under another's name would be worse than a spinner.
 */
export default function ServiceDrawer({
    service,
    pending,
    onClose,
    onAction,
    onEdit,
    onDelete,
}: {
    service: ServiceRow | null;
    pending: boolean;
    onAction: (
        service: ServiceRow,
        action: 'status' | 'flag' | 'duplicate' | 'price',
        payload?: Record<string, unknown>,
    ) => void;
    onClose: () => void;
    onEdit: (service: ServiceRow) => void;
    onDelete: (service: ServiceRow) => void;
}) {
    const [tab, setTab] = useState<TabKey>('overview');
    const [cache, setCache] = useState<Partial<Record<TabKey, TabPayload>>>({});
    const [loading, setLoading] = useState(false);

    const serviceId = service?.id ?? null;

    useEffect(() => {
        setTab('overview');
        setCache({});
    }, [serviceId]);

    const load = useCallback(
        async (which: TabKey) => {
            if (serviceId === null || cache[which]) {
                return;
            }

            setLoading(true);

            try {
                const response = await fetch(route('services.show', [serviceId, which]), {
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
        [serviceId, cache],
    );

    useEffect(() => {
        if (serviceId !== null) {
            void load(tab);
        }
    }, [serviceId, tab, load]);

    /** Drop the tabs an action just invalidated, rather than all five. */
    const invalidate = useCallback((keys: TabKey[]) => {
        setCache((current) => {
            const next = { ...current };
            keys.forEach((key) => delete next[key]);

            return next;
        });
    }, []);

    const data = cache[tab];

    return (
        <Dialog.Root open={service !== null} onOpenChange={(open) => !open && onClose()}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-50 bg-foreground/20 backdrop-blur-[1px] data-[state=closed]:animate-out data-[state=closed]:fade-out data-[state=open]:animate-in data-[state=open]:fade-in" />

                <Dialog.Content
                    className="fixed inset-y-0 right-0 z-50 flex w-full max-w-xl flex-col border-l border-border bg-background shadow-2xl duration-200 data-[state=closed]:animate-out data-[state=closed]:slide-out-to-right data-[state=open]:animate-in data-[state=open]:slide-in-from-right"
                    aria-describedby={undefined}
                >
                    {service === null ? null : (
                        <>
                            <header className="border-b border-border px-6 pb-3 pt-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <Dialog.Title className="font-heading text-lg font-extrabold leading-tight tracking-tight">
                                            {service.name}
                                        </Dialog.Title>
                                        <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <StatusBadge
                                                status={service.status}
                                                autoPaused={service.autoPaused}
                                            />
                                            <span>{service.platform}</span>
                                            {service.category && <span>· {service.category}</span>}
                                        </p>
                                        <p className="font-data mt-1.5 text-sm">
                                            {price(service.price)}
                                            <span className="text-xs text-muted-foreground">
                                                {' '}
                                                / 1,000
                                            </span>
                                            {service.margin !== null && (
                                                <span
                                                    className={`ms-2 text-xs ${
                                                        service.underwater
                                                            ? 'text-destructive'
                                                            : 'text-muted-foreground'
                                                    }`}
                                                >
                                                    {service.margin.toFixed(1)}% margin
                                                </span>
                                            )}
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
                                    aria-label="Service details"
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
                                                onEdit={() => onEdit(service)}
                                            />
                                        )}
                                        {tab === 'pricing' && data?.pricing && (
                                            <PricingTab
                                                data={data.pricing}
                                                pending={pending}
                                                onSave={(value) => {
                                                    onAction(service, 'price', { price: value });
                                                    invalidate(['pricing', 'logs', 'overview']);
                                                }}
                                            />
                                        )}
                                        {tab === 'orders' && data?.orders && (
                                            <OrdersTab orders={data.orders} />
                                        )}
                                        {tab === 'logs' && data?.logs && (
                                            <LogsTab logs={data.logs} />
                                        )}
                                        {tab === 'settings' && data?.settings && (
                                            <SettingsTab
                                                data={data.settings}
                                                pending={pending}
                                                onStatus={(status: ServiceStatus) => {
                                                    onAction(service, 'status', { status });
                                                    invalidate(['settings', 'overview']);
                                                }}
                                                onFlag={(flag, value) => {
                                                    onAction(service, 'flag', { flag, value });
                                                    invalidate(['settings', 'overview']);
                                                }}
                                            />
                                        )}
                                    </>
                                )}
                            </div>

                            <footer className="flex flex-wrap items-center gap-2 border-t border-border px-6 py-3">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={pending}
                                    onClick={() => onAction(service, 'duplicate')}
                                >
                                    <Copy className="size-3.5" />
                                    Duplicate
                                </Button>

                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={pending}
                                    onClick={() => onDelete(service)}
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
