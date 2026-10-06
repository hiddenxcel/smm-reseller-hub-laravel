import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Check, Loader2, Search, TriangleAlert, X } from 'lucide-react';
import { Dialog } from 'radix-ui';
import { useMemo, useState } from 'react';
import { price } from './bits';
import { CatalogueEntry, PricingRule } from './types';

/**
 * Importing services from a panel.
 *
 * Three steps rather than six: choose the panel, choose the services, choose
 * how they are priced. The extra steps in a longer wizard are all decisions a
 * reseller can make faster on one screen, and each one is a place to abandon.
 *
 * Pricing defaults to the reseller's own markup rules when they have any —
 * importing 200 services at a suggested margin they never chose is how a
 * catalogue ends up quietly wrong.
 */
export default function ImportDialog({
    open,
    panels,
    rules,
    onClose,
}: {
    open: boolean;
    panels: Array<{ id: number; name: string }>;
    rules: PricingRule[];
    onClose: () => void;
}) {
    const [panelId, setPanelId] = useState<number | null>(panels[0]?.id ?? null);
    const [entries, setEntries] = useState<CatalogueEntry[] | null>(null);
    // What the panel has in all — more than `entries` when the list was capped.
    const [panelTotal, setPanelTotal] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [importing, setImporting] = useState(false);

    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [markup, setMarkup] = useState('30');

    const activeRule = rules.find((rule) => rule.active) ?? null;

    const load = async () => {
        if (panelId === null) {
            return;
        }

        setLoading(true);
        setError(null);
        setEntries(null);
        setSelected(new Set());

        try {
            const response = await fetch(route('services.panel-catalogue', panelId), {
                headers: { Accept: 'application/json' },
            });

            // A failed request is not an empty panel. Reading the body of an
            // error as if it were a list is how "no services" used to be shown
            // when the real answer was "something went wrong".
            const body = response.ok ? await response.json().catch(() => null) : null;

            if (body === null) {
                setError('Something went wrong reading that panel. Please try again.');
            } else if (body.failed) {
                setError(body.message ?? 'Could not read that panel.');
            } else {
                setEntries(body.services ?? []);
                setPanelTotal(body.total ?? (body.services ?? []).length);
            }
        } catch {
            setError('Could not reach the panel.');
        } finally {
            setLoading(false);
        }
    };

    const visible = useMemo(() => {
        if (entries === null) {
            return [];
        }

        const term = search.trim().toLowerCase();

        return term === ''
            ? entries
            : entries.filter(
                  (entry) =>
                      entry.name.toLowerCase().includes(term) ||
                      entry.platform.toLowerCase().includes(term),
              );
    }, [entries, search]);

    /** What we would charge for an entry, given the chosen markup. */
    const priceFor = (entry: CatalogueEntry): string => {
        const cost = Number.parseFloat(entry.cost_price);
        const percent = Number.parseFloat(markup);

        if (!Number.isFinite(cost) || !Number.isFinite(percent)) {
            return entry.suggested_price;
        }

        return (cost * (1 + percent / 100)).toFixed(4);
    };

    const submit = () => {
        if (entries === null || panelId === null || selected.size === 0) {
            return;
        }

        setImporting(true);

        const services = entries
            .filter((entry) => selected.has(entry.provider_service_id))
            .map((entry) => ({
                provider_service_id: entry.provider_service_id,
                name: entry.name,
                platform: entry.platform,
                category: entry.category,
                cost_price: entry.cost_price,
                my_price: priceFor(entry),
                min_quantity: entry.min_quantity,
                max_quantity: entry.max_quantity,
            }));

        // Reuses the onboarding importer — it already does exactly this, and a
        // second copy would be a second place for the two to disagree.
        router.post(
            route('onboarding.services.store'),
            { panel_id: panelId, services },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onClose();
                    router.reload({ only: ['services', 'kpis', 'platforms', 'tabCounts'] });
                },
                onFinish: () => setImporting(false),
            },
        );
    };

    const importable = visible.filter((entry) => !entry.imported);

    return (
        <Dialog.Root open={open} onOpenChange={(next) => !next && onClose()}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-[60] bg-foreground/25 backdrop-blur-[1px] data-[state=open]:animate-in data-[state=open]:fade-in" />

                <Dialog.Content
                    className="fixed left-1/2 top-1/2 z-[60] flex max-h-[85dvh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 flex-col rounded-xl border border-border bg-background shadow-2xl data-[state=open]:animate-in data-[state=open]:fade-in data-[state=open]:zoom-in-95"
                    aria-describedby={undefined}
                >
                    <div className="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
                        <div>
                            <Dialog.Title className="font-heading text-lg font-extrabold tracking-tight">
                                Import services
                            </Dialog.Title>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Pick what to sell from a panel, and what to charge for it.
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
                        {panels.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-border py-10 text-center">
                                <p className="text-sm font-medium">No panel connected</p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Connect an SMM panel first, then import its services here.
                                </p>
                                <Button size="sm" className="mt-3" asChild>
                                    <a href={route('onboarding')}>Connect a panel</a>
                                </Button>
                            </div>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-end gap-3">
                                    <label className="flex flex-col gap-1">
                                        <span className="text-xs text-muted-foreground">
                                            Panel
                                        </span>
                                        <select
                                            value={panelId ?? ''}
                                            onChange={(event) =>
                                                setPanelId(Number(event.target.value))
                                            }
                                            className="h-9 rounded-lg border border-border bg-background px-2.5 text-sm outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                                        >
                                            {panels.map((panel) => (
                                                <option key={panel.id} value={panel.id}>
                                                    {panel.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>

                                    <Button size="sm" className="h-9" disabled={loading} onClick={load}>
                                        {loading && <Loader2 className="size-3.5 animate-spin" />}
                                        Load services
                                    </Button>

                                    <label className="flex flex-col gap-1">
                                        <span className="text-xs text-muted-foreground">
                                            Your markup
                                        </span>
                                        <div className="flex items-center gap-1.5">
                                            <input
                                                type="number"
                                                min="0"
                                                step="1"
                                                value={markup}
                                                onChange={(event) => setMarkup(event.target.value)}
                                                aria-label="Markup percent"
                                                className="font-data h-9 w-24 rounded-lg border border-border bg-background px-3 text-sm tabular-nums outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                                            />
                                            <span className="text-sm text-muted-foreground">%</span>
                                        </div>
                                    </label>
                                </div>

                                {activeRule && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Your rule “{activeRule.name}” ({activeRule.describe}) will
                                        take over on the next sync.
                                    </p>
                                )}

                                {error && (
                                    <div className="mt-4 flex items-start gap-2.5 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                                        <TriangleAlert
                                            className="mt-0.5 size-4 shrink-0"
                                            aria-hidden
                                        />
                                        <p>{error}</p>
                                    </div>
                                )}

                                {entries !== null && (
                                    <>
                                        <div className="mt-4 flex flex-wrap items-center gap-2">
                                            <div className="relative min-w-0 flex-1">
                                                <Search
                                                    className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                                                    aria-hidden
                                                />
                                                <input
                                                    type="search"
                                                    value={search}
                                                    onChange={(event) =>
                                                        setSearch(event.target.value)
                                                    }
                                                    placeholder="Filter the panel's list…"
                                                    aria-label="Filter services"
                                                    className="h-9 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm outline-none placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                                                />
                                            </div>

                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setSelected((current) =>
                                                        current.size >= importable.length
                                                            ? new Set()
                                                            : new Set(
                                                                  importable.map(
                                                                      (entry) =>
                                                                          entry.provider_service_id,
                                                                  ),
                                                              ),
                                                    )
                                                }
                                            >
                                                {selected.size >= importable.length && importable.length > 0
                                                    ? 'Clear'
                                                    : `Select ${importable.length}`}
                                            </Button>
                                        </div>

                                        {panelTotal > entries.length && (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                Showing the first {entries.length.toLocaleString('en-US')} of{' '}
                                                {panelTotal.toLocaleString('en-US')} services on this panel.
                                            </p>
                                        )}

                                        <div className="mt-3 max-h-72 overflow-y-auto rounded-lg border border-border">
                                            {visible.length === 0 ? (
                                                <p className="py-8 text-center text-sm text-muted-foreground">
                                                    {entries.length === 0
                                                        ? 'This panel returned no services.'
                                                        : 'Nothing matches that.'}
                                                </p>
                                            ) : (
                                                visible.map((entry) => {
                                                    const isSelected = selected.has(
                                                        entry.provider_service_id,
                                                    );

                                                    return (
                                                        <label
                                                            key={entry.provider_service_id}
                                                            className={[
                                                                'flex cursor-pointer items-center gap-3 border-b border-border px-3 py-2 last:border-0 transition-colors',
                                                                entry.imported
                                                                    ? 'cursor-default opacity-50'
                                                                    : 'hover:bg-muted/40',
                                                            ].join(' ')}
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={isSelected}
                                                                disabled={entry.imported}
                                                                onChange={() =>
                                                                    setSelected((current) => {
                                                                        const next = new Set(
                                                                            current,
                                                                        );
                                                                        next.has(
                                                                            entry.provider_service_id,
                                                                        )
                                                                            ? next.delete(
                                                                                  entry.provider_service_id,
                                                                              )
                                                                            : next.add(
                                                                                  entry.provider_service_id,
                                                                              );

                                                                        return next;
                                                                    })
                                                                }
                                                                className="size-4 shrink-0 cursor-pointer rounded border-border accent-primary"
                                                            />

                                                            <span className="min-w-0 flex-1">
                                                                <span className="block truncate text-sm">
                                                                    {entry.name}
                                                                </span>
                                                                <span className="block text-xs text-muted-foreground">
                                                                    {entry.platform}
                                                                    {entry.category &&
                                                                        ` · ${entry.category}`}
                                                                </span>
                                                            </span>

                                                            {entry.imported ? (
                                                                <span className="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                                                                    <Check
                                                                        className="size-3"
                                                                        aria-hidden
                                                                    />
                                                                    Imported
                                                                </span>
                                                            ) : (
                                                                <span className="shrink-0 text-right">
                                                                    <span className="font-data block text-xs text-muted-foreground tabular-nums">
                                                                        cost{' '}
                                                                        {price(
                                                                            Number(
                                                                                entry.cost_price,
                                                                            ),
                                                                        )}
                                                                    </span>
                                                                    <span className="font-data block text-sm tabular-nums">
                                                                        {price(
                                                                            Number(
                                                                                priceFor(entry),
                                                                            ),
                                                                        )}
                                                                    </span>
                                                                </span>
                                                            )}
                                                        </label>
                                                    );
                                                })
                                            )}
                                        </div>
                                    </>
                                )}
                            </>
                        )}
                    </div>

                    <div className="flex items-center justify-between gap-3 border-t border-border px-5 py-3">
                        <p className="text-xs text-muted-foreground">
                            {selected.size > 0 &&
                                `${selected.size.toLocaleString('en-US')} selected at cost +${markup}%`}
                        </p>
                        <div className="flex gap-2">
                            <Button variant="ghost" size="sm" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button
                                size="sm"
                                disabled={importing || selected.size === 0}
                                onClick={submit}
                            >
                                {importing && <Loader2 className="size-3.5 animate-spin" />}
                                Import {selected.size > 0 ? selected.size : ''}
                            </Button>
                        </div>
                    </div>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
