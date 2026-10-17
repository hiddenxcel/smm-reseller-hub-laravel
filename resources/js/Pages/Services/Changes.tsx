import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, TrendingDown, TrendingUp, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { price, relativeTime } from './bits';

interface Change {
    id: number;
    kind: 'cost' | 'removed';
    status: 'pending' | 'applied' | 'dismissed';
    serviceId: number | null;
    name: string;
    panel: string | null;
    oldCost: number | null;
    newCost: number | null;
    oldPrice: number | null;
    newPrice: number | null;
    underwater: boolean;
    note: string | null;
    at: string | null;
}

interface Props {
    autoSync: boolean;
    pending: Change[];
    recent: Change[];
}

function Money({ from, to }: { from: number | null; to: number | null }) {
    if (to === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    const changed = from !== null && from !== to;

    return (
        <span className="font-data inline-flex items-center gap-1.5 tabular-nums">
            {changed && (
                <>
                    <span className="text-muted-foreground line-through">{price(from)}</span>
                    <ArrowRight className="size-3 text-muted-foreground" aria-hidden />
                </>
            )}
            <span className="font-semibold">{price(to)}</span>
        </span>
    );
}

function AutoSwitch({ on, busy, onChange }: { on: boolean; busy: boolean; onChange: (next: boolean) => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            disabled={busy}
            onClick={() => onChange(!on)}
            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-60 ${
                on ? 'bg-primary' : 'bg-muted-foreground/30'
            }`}
        >
            <span
                className={`inline-block size-5 rounded-full bg-white shadow transition-transform ${
                    on ? 'translate-x-5' : 'translate-x-0.5'
                }`}
            />
        </button>
    );
}

function PendingRow({ change, busy, onAnswer }: { change: Change; busy: boolean; onAnswer: (action: 'approve' | 'dismiss') => void }) {
    const removed = change.kind === 'removed';
    const rose = !removed && (change.newCost ?? 0) > (change.oldCost ?? 0);

    return (
        <li className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0 space-y-1.5">
                <div className="flex items-center gap-2">
                    {removed ? (
                        <Trash2 className="size-4 shrink-0 text-destructive" aria-hidden />
                    ) : rose ? (
                        <TrendingUp className="size-4 shrink-0 text-amber-600" aria-hidden />
                    ) : (
                        <TrendingDown className="size-4 shrink-0 text-emerald-600" aria-hidden />
                    )}
                    <p className="truncate font-medium">{change.name}</p>
                </div>

                {removed ? (
                    <p className="text-sm text-muted-foreground">
                        Removed by {change.panel ?? 'the provider'}. Paused for now so nobody can order it. Allow to delete it from your list.
                    </p>
                ) : (
                    <div className="space-y-1 text-sm">
                        <p className="flex flex-wrap items-center gap-x-2">
                            <span className="text-muted-foreground">Provider cost</span>
                            <Money from={change.oldCost} to={change.newCost} />
                        </p>
                        <p className="flex flex-wrap items-center gap-x-2">
                            <span className="text-muted-foreground">Your price</span>
                            <Money from={change.oldPrice} to={change.newPrice} />
                        </p>
                        {change.underwater && (
                            <p className="text-xs font-medium text-destructive">
                                Selling below cost if you leave the price as it is.
                            </p>
                        )}
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    {change.panel} · {relativeTime(change.at)}
                </p>
            </div>

            <div className="flex shrink-0 gap-2">
                <Button size="sm" variant="outline" className="h-9" disabled={busy} onClick={() => onAnswer('dismiss')}>
                    <X className="size-3.5" />
                    Dismiss
                </Button>
                <Button size="sm" className="h-9" disabled={busy} onClick={() => onAnswer('approve')}>
                    <Check className="size-3.5" />
                    {removed ? 'Remove' : 'Allow'}
                </Button>
            </div>
        </li>
    );
}

export default function Changes({ autoSync, pending, recent }: Props) {
    const [busy, setBusy] = useState(false);
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    const answer = (change: Change, action: 'approve' | 'dismiss') =>
        router.post(route(`services.changes.${action}`, change.id), {}, options);

    const answerAll = (action: 'approve' | 'dismiss') =>
        router.post(route('services.changes.resolve-all'), { action }, options);

    const toggle = (enabled: boolean) => router.post(route('services.changes.auto'), { enabled }, options);

    return (
        <AuthenticatedLayout>
            <Head title="Panel changes" />

            <div className="mx-auto max-w-3xl space-y-5 px-4 py-5 sm:px-8">
                <div>
                    <Link href={route('services.index')} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-3.5" aria-hidden />
                        Services
                    </Link>
                    <h1 className="font-heading mt-1 text-xl font-extrabold tracking-tight sm:text-2xl">Panel changes</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Prices that moved and services your providers removed. Allow each one, or dismiss it.
                    </p>
                </div>

                <div className="flex items-start justify-between gap-4 rounded-xl border border-border bg-card p-4">
                    <div className="min-w-0">
                        <p className="font-medium">Auto-sync</p>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {autoSync
                                ? 'On. Prices follow your providers by themselves (through your markup rules, or keeping the margin you had), and removed services are paused.'
                                : 'Off. Nothing changes until you allow it here. Checked every hour.'}
                        </p>
                    </div>
                    <AutoSwitch on={autoSync} busy={busy} onChange={toggle} />
                </div>

                <section className="space-y-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="font-heading text-base font-bold">
                            Waiting for you
                            {pending.length > 0 && (
                                <span className="font-data ms-2 rounded-full bg-amber-500/15 px-2 py-0.5 text-xs text-amber-600 tabular-nums dark:text-amber-400">
                                    {pending.length}
                                </span>
                            )}
                        </h2>

                        {pending.length > 1 && (
                            <div className="flex gap-2">
                                <Button size="sm" variant="outline" className="h-8" disabled={busy} onClick={() => answerAll('dismiss')}>
                                    Dismiss all
                                </Button>
                                <Button size="sm" className="h-8" disabled={busy} onClick={() => answerAll('approve')}>
                                    Allow all
                                </Button>
                            </div>
                        )}
                    </div>

                    {pending.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                            Nothing waiting. Your prices match your providers.
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {pending.map((change) => (
                                <PendingRow key={change.id} change={change} busy={busy} onAnswer={(action) => answer(change, action)} />
                            ))}
                        </ul>
                    )}
                </section>

                {recent.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-heading text-base font-bold">Recently handled</h2>
                        <ul className="divide-y divide-border rounded-xl border border-border bg-card text-sm">
                            {recent.map((change) => (
                                <li key={change.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <div className="min-w-0">
                                        <p className="truncate">{change.name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {change.kind === 'removed' ? 'Removed by provider' : 'Cost changed'} · {relativeTime(change.at)}
                                        </p>
                                    </div>
                                    <span
                                        className={`shrink-0 text-xs font-medium ${
                                            change.status === 'applied' ? 'text-emerald-600' : 'text-muted-foreground'
                                        }`}
                                    >
                                        {change.status === 'applied' ? (change.note === 'Applied by auto-sync' ? 'Auto' : 'Allowed') : 'Dismissed'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
