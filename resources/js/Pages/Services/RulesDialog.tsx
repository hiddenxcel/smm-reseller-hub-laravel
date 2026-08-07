import { Button } from '@/components/ui/button';
import { router, useForm } from '@inertiajs/react';
import { Loader2, Plus, Trash2, Wand2, X } from 'lucide-react';
import { Dialog } from 'radix-ui';
import { FormEvent, useState } from 'react';
import { price } from './bits';
import { PricingRule } from './types';

/**
 * Standing markup rules.
 *
 * These are what keep a reseller's margin intact when a panel moves its costs:
 * the sync re-applies them, so "Instagram is cost +30%" stays true without
 * anyone re-pricing a thousand services by hand.
 *
 * First match wins, so the order matters and the list says so — a catch-all
 * rule placed above a specific one would swallow it, and that is invisible
 * unless the screen tells you.
 */
export default function RulesDialog({
    open,
    rules,
    panels,
    onClose,
}: {
    open: boolean;
    rules: PricingRule[];
    panels: Array<{ id: number; name: string }>;
    onClose: () => void;
}) {
    const [adding, setAdding] = useState(false);

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
                                Markup rules
                            </Dialog.Title>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Keep your margin when panel costs move. Applied on every sync.
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
                        {rules.length === 0 && !adding && (
                            <div className="rounded-xl border border-dashed border-border py-10 text-center">
                                <Wand2
                                    className="mx-auto size-6 text-muted-foreground"
                                    aria-hidden
                                />
                                <p className="mt-2 text-sm font-medium">No rules yet</p>
                                <p className="mx-auto mt-1 max-w-sm text-xs text-muted-foreground">
                                    Without a rule, a panel raising its cost quietly eats your
                                    margin — your price stays where it was.
                                </p>
                            </div>
                        )}

                        {rules.length > 0 && (
                            <ol className="space-y-2">
                                {rules.map((rule, index) => (
                                    <li
                                        key={rule.id}
                                        className="flex items-start gap-3 rounded-xl border border-border p-3"
                                    >
                                        <span className="font-data mt-0.5 shrink-0 text-xs text-muted-foreground tabular-nums">
                                            {index + 1}
                                        </span>

                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {rule.name}
                                                {!rule.active && (
                                                    <span className="ms-2 rounded-full bg-muted px-1.5 py-0.5 text-[0.7rem] font-normal text-muted-foreground">
                                                        off
                                                    </span>
                                                )}
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {rule.platform ?? 'Any platform'}
                                                {rule.panelId !== null &&
                                                    ` · ${panels.find((p) => p.id === rule.panelId)?.name ?? 'panel'}`}
                                                {' → '}
                                                {rule.describe}
                                            </p>
                                            {(rule.minProfit !== null ||
                                                rule.maxProfit !== null) && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {rule.minProfit !== null &&
                                                        `at least ${price(rule.minProfit)} profit`}
                                                    {rule.minProfit !== null &&
                                                        rule.maxProfit !== null &&
                                                        ', '}
                                                    {rule.maxProfit !== null &&
                                                        `at most ${price(rule.maxProfit)}`}
                                                </p>
                                            )}
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.delete(
                                                    route('services.rules.destroy', rule.id),
                                                    { preserveScroll: true },
                                                )
                                            }
                                            aria-label={`Remove ${rule.name}`}
                                            className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                        >
                                            <Trash2 className="size-3.5" />
                                        </button>
                                    </li>
                                ))}
                            </ol>
                        )}

                        {/* Only meaningful with more than one rule — with a
                            single rule there is no order to get wrong. */}
                        {rules.length > 1 && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                The first rule that matches a service wins; the rest are skipped.
                            </p>
                        )}

                        {adding ? (
                            <RuleForm
                                panels={panels}
                                onDone={() => setAdding(false)}
                            />
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                className="mt-3 w-full"
                                onClick={() => setAdding(true)}
                            >
                                <Plus className="size-3.5" />
                                Add a rule
                            </Button>
                        )}
                    </div>

                    <div className="flex items-center justify-between gap-3 border-t border-border px-5 py-3">
                        <p className="text-xs text-muted-foreground">
                            Rules apply on sync — or run them now.
                        </p>
                        <div className="flex gap-2">
                            <Button variant="ghost" size="sm" onClick={onClose}>
                                Close
                            </Button>
                            <Button
                                size="sm"
                                disabled={rules.filter((rule) => rule.active).length === 0}
                                onClick={() =>
                                    router.post(
                                        route('services.rules.apply'),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Apply now
                            </Button>
                        </div>
                    </div>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

function RuleForm({
    panels,
    onDone,
}: {
    panels: Array<{ id: number; name: string }>;
    onDone: () => void;
}) {
    const form = useForm({
        name: '',
        platform: '',
        panel_id: '' as string | number,
        mode: 'percent',
        amount: '30',
        min_profit: '',
        max_profit: '',
        round_to: '',
        active: true,
        sort_order: 0,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // Blank optional fields post as empty strings; the rules validator
        // wants nulls, and "any platform" is genuinely a null.
        form.transform((data) => ({
            ...data,
            platform: data.platform === '' ? null : data.platform,
            panel_id: data.panel_id === '' ? null : Number(data.panel_id),
            min_profit: data.min_profit === '' ? null : data.min_profit,
            max_profit: data.max_profit === '' ? null : data.max_profit,
            round_to: data.round_to === '' ? null : data.round_to,
        }));

        form.post(route('services.rules.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };

    return (
        <form
            onSubmit={submit}
            className="mt-3 space-y-3 rounded-xl border border-border bg-muted/30 p-3"
        >
            <Field label="Name" error={form.errors.name}>
                <input
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    placeholder="Instagram markup"
                    className={inputClass}
                />
            </Field>

            <div className="grid grid-cols-2 gap-3">
                <Field label="Platform" error={form.errors.platform}>
                    <input
                        value={form.data.platform}
                        onChange={(event) => form.setData('platform', event.target.value)}
                        placeholder="Any"
                        className={inputClass}
                    />
                </Field>

                <Field label="Panel" error={form.errors.panel_id}>
                    <select
                        value={form.data.panel_id}
                        onChange={(event) => form.setData('panel_id', event.target.value)}
                        className={inputClass}
                    >
                        <option value="">Any</option>
                        {panels.map((panel) => (
                            <option key={panel.id} value={panel.id}>
                                {panel.name}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
                <Field label="Markup" error={form.errors.mode}>
                    <select
                        value={form.data.mode}
                        onChange={(event) => form.setData('mode', event.target.value)}
                        className={inputClass}
                    >
                        <option value="percent">Cost + percent</option>
                        <option value="fixed">Cost + amount</option>
                        <option value="multiplier">Cost × factor</option>
                    </select>
                </Field>

                <Field label="Amount" error={form.errors.amount}>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.data.amount}
                        onChange={(event) => form.setData('amount', event.target.value)}
                        className={`${inputClass} font-data tabular-nums`}
                    />
                </Field>
            </div>

            <div className="grid grid-cols-3 gap-3">
                <Field label="Min profit" error={form.errors.min_profit}>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.data.min_profit}
                        onChange={(event) => form.setData('min_profit', event.target.value)}
                        placeholder="—"
                        className={`${inputClass} font-data tabular-nums`}
                    />
                </Field>
                <Field label="Max profit" error={form.errors.max_profit}>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.data.max_profit}
                        onChange={(event) => form.setData('max_profit', event.target.value)}
                        placeholder="—"
                        className={`${inputClass} font-data tabular-nums`}
                    />
                </Field>
                <Field label="Round to" error={form.errors.round_to}>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.data.round_to}
                        onChange={(event) => form.setData('round_to', event.target.value)}
                        placeholder="—"
                        className={`${inputClass} font-data tabular-nums`}
                    />
                </Field>
            </div>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" size="sm" disabled={form.processing}>
                    {form.processing && <Loader2 className="size-3.5 animate-spin" />}
                    Save rule
                </Button>
            </div>
        </form>
    );
}

const inputClass =
    'h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30';

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-muted-foreground">{label}</span>
            {children}
            {error && <span className="mt-1 block text-xs text-destructive">{error}</span>}
        </label>
    );
}
