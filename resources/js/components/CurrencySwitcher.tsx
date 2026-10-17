import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router, usePage } from '@inertiajs/react';
import { Coins, Loader2 } from 'lucide-react';
import { useState } from 'react';

type Mode = 'relabel' | 'convert';

type Preview = {
    same: boolean;
    from: string;
    to: string;
    factor: string;
    services: number;
    customers: number;
    walletTotal: string;
    orders: number;
    panelMatches: boolean;
    panelCurrencies: string[];
    sample: { name: string; from: string; to: string }[];
};

const nf = (value: string, places = 2) =>
    Number(value).toLocaleString('en-US', {
        minimumFractionDigits: places,
        maximumFractionDigits: places,
    });

/**
 * The currency this reseller's shop is priced in.
 *
 * Choosing one never changes anything by itself: it opens a confirmation that
 * says what would happen, and asks which of the two kinds of change is meant.
 * Relabelling and converting do opposite things to the numbers, so this has
 * no default the reseller did not see.
 */
export default function CurrencySwitcher() {
    const { pricingCurrency, auth } = usePage().props;

    const [preview, setPreview] = useState<Preview | null>(null);
    const [mode, setMode] = useState<Mode>('convert');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    // A team member does not get to re-price the shop.
    if (!pricingCurrency || auth.member) {
        return null;
    }

    const choose = async (code: string) => {
        if (code === pricingCurrency.current) {
            return;
        }

        setLoading(true);
        setProblem(null);

        try {
            const response = await fetch(
                `${route('settings.pricing-currency.preview')}?currency=${encodeURIComponent(code)}`,
                { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
            );

            if (!response.ok) {
                throw new Error('preview failed');
            }

            const data = (await response.json()) as Preview;

            if (data.same) {
                return;
            }

            // A panel that already reports in this currency is the sign the
            // prices are in it too and only the label is wrong.
            setMode(data.panelMatches ? 'relabel' : 'convert');
            setPreview(data);
        } catch {
            setProblem('Could not load that currency. Try again.');
        } finally {
            setLoading(false);
        }
    };

    const confirm = () => {
        if (!preview) {
            return;
        }

        router.patch(
            route('settings.pricing-currency.update'),
            { currency: preview.to, mode },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setPreview(null);
                },
            },
        );
    };

    return (
        <>
            <label className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
                {loading ? (
                    <Loader2 className="size-4 shrink-0 animate-spin" />
                ) : (
                    <Coins className="size-4 shrink-0" />
                )}
                <span className="sr-only">Currency</span>
                <select
                    value={pricingCurrency.current}
                    onChange={(event) => choose(event.target.value)}
                    className="min-w-0 flex-1 cursor-pointer bg-transparent text-sm outline-none"
                >
                    {pricingCurrency.choices.map((choice) => (
                        <option
                            key={choice.code}
                            value={choice.code}
                            className="bg-background text-foreground"
                        >
                            {choice.code} — {choice.name}
                        </option>
                    ))}
                </select>
            </label>

            {problem && <p className="px-2 text-xs text-destructive">{problem}</p>}

            <Dialog open={preview !== null} onOpenChange={(open) => !open && !busy && setPreview(null)}>
                {/* One column that may shrink: a long service name must truncate
                    inside the box, not stretch the grid and push the buttons out. */}
                <DialogContent className="grid-cols-[minmax(0,1fr)] sm:max-w-md">
                    {preview && (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Switch from {preview.from} to {preview.to}?
                                </DialogTitle>
                                <DialogDescription>
                                    Choose what happens to the numbers already in your shop.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-2">
                                <ModeOption
                                    selected={mode === 'relabel'}
                                    onSelect={() => setMode('relabel')}
                                    title={`My prices are already in ${preview.to}`}
                                    body={`Only the label changes. Every price, wallet and order keeps its number. Use this if your panel already sells in ${preview.to}.`}
                                    hint={
                                        preview.panelMatches
                                            ? `Your panel reports in ${preview.to}, so this is probably the right one.`
                                            : undefined
                                    }
                                />
                                <ModeOption
                                    selected={mode === 'convert'}
                                    onSelect={() => setMode('convert')}
                                    title={`Convert my ${preview.from} amounts to ${preview.to}`}
                                    body={`Re-expressed at 1 ${preview.from} = ${nf(preview.factor, 4)} ${preview.to}: ${preview.services} service prices, ${preview.customers} wallets (${nf(preview.walletTotal)} ${preview.from} in total) and ${preview.orders} past orders.`}
                                />
                            </div>

                            {mode === 'convert' && preview.sample.length > 0 && (
                                <div className="rounded-lg border border-border">
                                    <p className="border-b border-border px-3 py-1.5 text-xs font-medium text-muted-foreground">
                                        Example prices
                                    </p>
                                    <ul className="divide-y divide-border">
                                        {preview.sample.map((row) => (
                                            <li
                                                key={row.name}
                                                className="flex items-center justify-between gap-3 px-3 py-1.5 text-xs"
                                            >
                                                <span className="min-w-0 truncate">{row.name}</span>
                                                <span className="shrink-0 tabular-nums text-muted-foreground">
                                                    {nf(row.from, 2)} → {nf(row.to, 2)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <DialogFooter>
                                <Button variant="outline" onClick={() => setPreview(null)} disabled={busy}>
                                    Cancel
                                </Button>
                                <Button onClick={confirm} disabled={busy}>
                                    {busy && <Loader2 className="size-4 animate-spin" />}
                                    {mode === 'relabel' ? `Label as ${preview.to}` : `Convert to ${preview.to}`}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function ModeOption({
    selected,
    onSelect,
    title,
    body,
    hint,
}: {
    selected: boolean;
    onSelect: () => void;
    title: string;
    body: string;
    hint?: string;
}) {
    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={selected}
            className={[
                'w-full rounded-lg border p-3 text-left transition-colors',
                selected ? 'border-primary bg-primary/5' : 'border-border hover:bg-accent',
            ].join(' ')}
        >
            <span className="block text-sm font-medium">{title}</span>
            <span className="mt-0.5 block text-xs text-muted-foreground">{body}</span>
            {hint && <span className="mt-1 block text-xs font-medium text-primary">{hint}</span>}
        </button>
    );
}
