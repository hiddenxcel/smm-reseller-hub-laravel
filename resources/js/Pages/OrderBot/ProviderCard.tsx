import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { CircleCheck, Plug, RefreshCw, TriangleAlert, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { relativeTime } from './inbox-bits';
import { Provider } from './types';

/**
 * One connected panel: what it is, what it holds, and the two things you can
 * do to it.
 *
 * Removing one is the dangerous action, so the count of imported services is
 * shown before the confirmation rather than in it — the number is the reason
 * to stop, and it should be visible before the finger moves.
 */
export function ProviderCard({ provider }: { provider: Provider }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    const isDown = provider.status !== 'active';

    return (
        <section className="rounded-xl border border-border bg-card p-5">
            <header className="flex flex-wrap items-start gap-3">
                <span
                    className="grid size-10 shrink-0 place-items-center rounded-xl bg-muted"
                    aria-hidden
                >
                    <Plug className="size-4 text-muted-foreground" />
                </span>

                <div className="min-w-0 flex-1">
                    <h2 className="font-heading truncate font-bold">{provider.name}</h2>
                    <p className="truncate text-xs text-muted-foreground">
                        {provider.panelType} · {provider.apiUrl}
                    </p>
                </div>

                <StatusChip status={provider.status} />
            </header>

            <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <Stat
                    label="Balance"
                    value={
                        provider.balance === null
                            ? '—'
                            : `${provider.currency ? `${provider.currency} ` : ''}${provider.balance.toFixed(2)}`
                    }
                />
                <Stat
                    label="Imported"
                    value={provider.importedServices.toLocaleString('en-US')}
                />
                <Stat
                    label="Checked"
                    value={
                        provider.lastCheckedAt === null
                            ? 'never'
                            : relativeTime(provider.lastCheckedAt)
                    }
                />
            </dl>

            {isDown && (
                <p className="mt-3 flex items-start gap-2 rounded-lg bg-[oklch(0.77_0.16_70/0.16)] p-2.5 text-xs text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                    This panel did not answer the last time we checked. Orders sent to it
                    will fail until it is back.
                </p>
            )}

            <footer className="mt-4 flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onClick={() => {
                        setBusy(true);
                        router.post(
                            route('order-bot.providers.refresh', provider.id),
                            {},
                            { preserveScroll: true, onFinish: () => setBusy(false) },
                        );
                    }}
                >
                    <RefreshCw className={`size-4 ${busy ? 'animate-spin' : ''}`} />
                    {busy ? 'Checking…' : 'Check now'}
                </Button>

                {confirming ? (
                    <ConfirmRemove
                        provider={provider}
                        onCancel={() => setConfirming(false)}
                    />
                ) : (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-destructive hover:text-destructive"
                        onClick={() => setConfirming(true)}
                    >
                        <Trash2 className="size-4" />
                        Remove
                    </Button>
                )}
            </footer>
        </section>
    );
}

/**
 * Says what removing this will cost before it happens.
 *
 * The services are hidden rather than deleted, and saying so matters: a
 * reseller who thinks their pricing is about to be destroyed will not press
 * the button, and a reseller who thinks nothing happens will press it blind.
 */
function ConfirmRemove({
    provider,
    onCancel,
}: {
    provider: Provider;
    onCancel: () => void;
}) {
    const [busy, setBusy] = useState(false);

    return (
        <div className="w-full rounded-lg border border-destructive/40 bg-destructive/5 p-3">
            <p className="text-sm font-semibold">Remove {provider.name}?</p>

            <p className="mt-1 text-sm text-muted-foreground">
                {provider.importedServices > 0 ? (
                    <>
                        Its{' '}
                        <strong className="text-foreground">
                            {provider.importedServices.toLocaleString('en-US')}
                        </strong>{' '}
                        imported {provider.importedServices === 1 ? 'service' : 'services'}{' '}
                        will be hidden, so the bot stops offering them. Your prices are
                        kept.
                    </>
                ) : (
                    'Nothing has been imported from it, so nothing else changes.'
                )}
            </p>

            <div className="mt-3 flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
                    Cancel
                </Button>

                <Button
                    type="button"
                    size="sm"
                    className="bg-destructive text-white hover:bg-destructive/90"
                    disabled={busy}
                    onClick={() => {
                        setBusy(true);
                        router.delete(route('order-bot.providers.destroy', provider.id), {
                            preserveScroll: true,
                            onFinish: () => setBusy(false),
                        });
                    }}
                >
                    {busy ? 'Removing…' : 'Remove'}
                </Button>
            </div>
        </div>
    );
}

function StatusChip({ status }: { status: string }) {
    const ok = status === 'active';

    return (
        <span
            className={[
                'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                ok
                    ? 'bg-primary/10 text-primary'
                    : 'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]',
            ].join(' ')}
        >
            {ok ? (
                <CircleCheck className="size-3.5" aria-hidden />
            ) : (
                <TriangleAlert className="size-3.5" aria-hidden />
            )}
            {ok ? 'Connected' : 'Not answering'}
        </span>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">
                {label}
            </dt>
            <dd className="font-data mt-0.5 text-sm font-semibold">{value}</dd>
        </div>
    );
}
