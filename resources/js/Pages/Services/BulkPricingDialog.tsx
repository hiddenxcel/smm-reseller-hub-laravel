import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { ArrowRight, Loader2, TriangleAlert, X } from 'lucide-react';
import { Dialog } from 'radix-ui';
import { useCallback, useEffect, useState } from 'react';
import { price } from './bits';
import { BulkMode, PricingPreviewRow } from './types';

const MODES: Array<{ key: BulkMode; label: string; hint: string; suffix: string }> = [
    { key: 'percent', label: 'By percent', hint: 'Change each price by a percentage of itself', suffix: '%' },
    { key: 'fixed', label: 'By amount', hint: 'Add or take off a flat amount per 1,000', suffix: '' },
    { key: 'markup', label: 'From cost', hint: 'Re-derive every price as cost plus a markup', suffix: '%' },
    { key: 'set', label: 'Set price', hint: 'Give every selected service the same price', suffix: '' },
];

/**
 * The bulk pricing tool.
 *
 * Nothing is written until the reseller has seen the exact lines. The preview
 * comes from the server rather than being recomputed in the browser, so what
 * they approve is literally what will be saved — a second implementation in
 * JavaScript is how a preview and its apply drift apart.
 */
export default function BulkPricingDialog({
    open,
    ids,
    limit,
    onClose,
    onApplied,
}: {
    open: boolean;
    ids: number[];
    limit: number;
    onClose: () => void;
    onApplied: () => void;
}) {
    const [mode, setMode] = useState<BulkMode>('percent');
    const [amount, setAmount] = useState('10');
    const [decrease, setDecrease] = useState(false);
    const [minProfit, setMinProfit] = useState('');

    const [rows, setRows] = useState<PricingPreviewRow[] | null>(null);
    const [describe, setDescribe] = useState('');
    const [loading, setLoading] = useState(false);
    const [applying, setApplying] = useState(false);

    const capped = ids.length > limit;
    const affected = Math.min(ids.length, limit);

    const payload = useCallback(
        () => ({
            mode,
            amount: amount === '' ? '0' : amount,
            decrease: mode === 'percent' || mode === 'fixed' ? decrease : false,
            min_profit: minProfit === '' ? null : minProfit,
            ids: ids.slice(0, limit),
        }),
        [mode, amount, decrease, minProfit, ids, limit],
    );

    // Re-preview whenever the terms change, on a short delay so typing an
    // amount does not fire a request per digit.
    useEffect(() => {
        if (!open || ids.length === 0) {
            return;
        }

        const parsed = Number.parseFloat(amount);

        if (!Number.isFinite(parsed) || parsed < 0) {
            setRows(null);

            return;
        }

        setLoading(true);

        const timer = setTimeout(() => {
            void (async () => {
                try {
                    const response = await fetch(route('services.pricing.preview'), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-XSRF-TOKEN': decodeURIComponent(
                                document.cookie
                                    .split('; ')
                                    .find((row) => row.startsWith('XSRF-TOKEN='))
                                    ?.split('=')[1] ?? '',
                            ),
                        },
                        body: JSON.stringify(payload()),
                    });

                    if (response.ok) {
                        const body = await response.json();

                        setRows(body.rows);
                        setDescribe(body.describe);
                    }
                } finally {
                    setLoading(false);
                }
            })();
        }, 300);

        return () => clearTimeout(timer);
    }, [open, amount, mode, decrease, minProfit, ids, limit, payload]);

    const apply = () => {
        setApplying(true);

        router.post(route('services.pricing.apply'), payload(), {
            preserveScroll: true,
            onSuccess: () => {
                onApplied();
                onClose();
            },
            onFinish: () => setApplying(false),
        });
    };

    const changing = rows?.filter((row) => row.from !== row.to) ?? [];
    const losses = rows?.filter((row) => row.underwater) ?? [];

    return (
        <Dialog.Root open={open} onOpenChange={(next) => !next && onClose()}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-[60] bg-foreground/25 backdrop-blur-[1px] data-[state=open]:animate-in data-[state=open]:fade-in" />

                <Dialog.Content
                    className="fixed left-1/2 top-1/2 z-[60] flex max-h-[85dvh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 flex-col rounded-xl border border-border bg-background shadow-2xl data-[state=open]:animate-in data-[state=open]:fade-in data-[state=open]:zoom-in-95"
                    aria-describedby={undefined}
                >
                    <div className="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
                        <div>
                            <Dialog.Title className="font-heading text-lg font-extrabold tracking-tight">
                                Bulk pricing
                            </Dialog.Title>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {affected.toLocaleString('en-US')} selected
                                {capped && ` (capped from ${ids.length.toLocaleString('en-US')})`}
                            </p>
                        </div>
                        <Dialog.Close asChild>
                            <button
                                type="button"
                                className="rounded-lg p-1 text-muted-foreground transition-colors hover:bg-accent"
                                aria-label="Close"
                            >
                                <X className="size-4" />
                            </button>
                        </Dialog.Close>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {MODES.map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => setMode(option.key)}
                                    className={[
                                        'rounded-lg border p-2.5 text-left transition-colors',
                                        mode === option.key
                                            ? 'border-primary/40 bg-primary/5'
                                            : 'border-border hover:bg-accent/50',
                                    ].join(' ')}
                                >
                                    <span className="block text-sm font-medium">
                                        {option.label}
                                    </span>
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        {option.hint}
                                    </span>
                                </button>
                            ))}
                        </div>

                        <div className="mt-4 flex flex-wrap items-end gap-3">
                            <label className="flex flex-col gap-1">
                                <span className="text-xs text-muted-foreground">Amount</span>
                                <div className="flex items-center gap-1.5">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={amount}
                                        onChange={(event) => setAmount(event.target.value)}
                                        aria-label="Amount"
                                        className="font-data h-9 w-28 rounded-lg border border-border bg-background px-3 text-sm tabular-nums outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                                    />
                                    <span className="text-sm text-muted-foreground">
                                        {MODES.find((option) => option.key === mode)?.suffix}
                                    </span>
                                </div>
                            </label>

                            {(mode === 'percent' || mode === 'fixed') && (
                                <div className="flex gap-1">
                                    <Button
                                        size="sm"
                                        variant={decrease ? 'outline' : 'default'}
                                        onClick={() => setDecrease(false)}
                                    >
                                        Increase
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant={decrease ? 'default' : 'outline'}
                                        onClick={() => setDecrease(true)}
                                    >
                                        Decrease
                                    </Button>
                                </div>
                            )}

                            <label className="flex flex-col gap-1">
                                <span className="text-xs text-muted-foreground">
                                    Never earn less than
                                </span>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={minProfit}
                                    onChange={(event) => setMinProfit(event.target.value)}
                                    placeholder="optional"
                                    aria-label="Minimum profit"
                                    className="font-data h-9 w-32 rounded-lg border border-border bg-background px-3 text-sm tabular-nums outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                                />
                            </label>
                        </div>

                        {losses.length > 0 && (
                            <div className="mt-4 flex items-start gap-2.5 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                                <p>
                                    {losses.length.toLocaleString('en-US')}{' '}
                                    {losses.length === 1 ? 'service' : 'services'} would sell below
                                    cost. Set a minimum profit above to stop that.
                                </p>
                            </div>
                        )}

                        <div className="mt-4">
                            <div className="mb-2 flex items-center justify-between">
                                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Preview
                                </p>
                                {loading && (
                                    <Loader2
                                        className="size-3.5 animate-spin text-muted-foreground"
                                        aria-hidden
                                    />
                                )}
                            </div>

                            {rows === null ? (
                                <p className="py-6 text-center text-sm text-muted-foreground">
                                    Enter an amount to see what would change.
                                </p>
                            ) : changing.length === 0 ? (
                                <p className="py-6 text-center text-sm text-muted-foreground">
                                    Nothing would change.
                                </p>
                            ) : (
                                <div className="max-h-56 overflow-y-auto rounded-lg border border-border">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {changing.slice(0, 100).map((row) => (
                                                <tr
                                                    key={row.id}
                                                    className="border-b border-border last:border-0"
                                                >
                                                    <td className="max-w-0 px-3 py-1.5">
                                                        <span className="block truncate">
                                                            {row.name}
                                                        </span>
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-1.5 text-right">
                                                        <span className="font-data text-xs text-muted-foreground tabular-nums">
                                                            {price(Number(row.from))}
                                                        </span>
                                                        <ArrowRight
                                                            className="mx-1 inline size-3 text-muted-foreground"
                                                            aria-hidden
                                                        />
                                                        <span
                                                            className={`font-data text-xs tabular-nums ${
                                                                row.underwater
                                                                    ? 'text-destructive'
                                                                    : ''
                                                            }`}
                                                        >
                                                            {price(Number(row.to))}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center justify-between gap-3 border-t border-border px-5 py-3">
                        <p className="text-xs text-muted-foreground">
                            {changing.length > 0 &&
                                `${changing.length.toLocaleString('en-US')} would change · ${describe}`}
                        </p>
                        <div className="flex gap-2">
                            <Button variant="ghost" size="sm" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button
                                size="sm"
                                disabled={applying || changing.length === 0}
                                onClick={apply}
                            >
                                {applying && <Loader2 className="size-3.5 animate-spin" />}
                                Apply to {changing.length.toLocaleString('en-US')}
                            </Button>
                        </div>
                    </div>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
