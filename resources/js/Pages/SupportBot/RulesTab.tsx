import { router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { Card, Field, inputClass } from '../OrderBot/bits';
import { PanelOption, RefillPolicy, Rule } from './types';

/**
 * Refill guarantee rules.
 *
 * These decide one thing: when a customer picks "Refill", does the bot submit
 * it or refuse. The bot matches the ordered service's name against these
 * keywords, so they are substrings of service names, not service ids.
 *
 * Exclusions are listed first because that is the order the matcher applies
 * them in — a `no_guarantee` keyword beats a `guarantee` one, so "no refill on
 * anything with 'cheap' in the name" holds even alongside a broad grant.
 *
 * The rules come first and adding one opens in place: a reseller opening this
 * tab is mostly checking what is set, not adding.
 */
export function RulesTab({
    rules,
    panels,
    policy,
}: {
    rules: Rule[];
    panels: PanelOption[];
    policy: RefillPolicy;
}) {
    const exclusions = rules.filter((rule) => rule.type === 'no_guarantee');
    const grants = rules.filter((rule) => rule.type === 'guarantee');

    const [adding, setAdding] = useState(false);

    return (
        <div className="space-y-4 sm:space-y-6">
            <AutomaticCard policy={policy} />

            {rules.length === 0 && (
                <p className="rounded-2xl border border-dashed border-border px-4 py-5 text-sm text-muted-foreground">
                    No rules, and none are needed while automatic reading is on. Add one only to
                    override a service: a keyword like{' '}
                    <span className="font-data text-foreground">followers</span> covers every
                    service with that word in its name.
                </p>
            )}

            {exclusions.length > 0 && (
                <RuleGroup
                    title="No refill"
                    note="Checked first — these win over any guarantee."
                    rules={exclusions}
                />
            )}

            {grants.length > 0 && <RuleGroup title="Refill guaranteed" rules={grants} />}

            {adding ? (
                <AddRuleForm
                    panels={panels}
                    onCancel={rules.length > 0 ? () => setAdding(false) : undefined}
                />
            ) : (
                <button
                    type="button"
                    onClick={() => setAdding(true)}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-border bg-background px-4 py-2.5 text-sm font-medium transition-colors hover:bg-accent sm:w-auto"
                >
                    <Plus className="size-4" aria-hidden />
                    Add a rule
                </button>
            )}
        </div>
    );
}

const DEFAULT_CHOICES: Array<{ value: RefillPolicy['default']; label: string; note: string }> = [
    {
        value: 'refuse',
        label: 'Refuse',
        note: 'Safest. No refill unless the service or a rule says there is one.',
    },
    {
        value: 'allow',
        label: 'Allow',
        note: 'Send it to the panel and let the panel decide.',
    },
    {
        value: 'human',
        label: 'Ask my team',
        note: 'The customer is told, and your staff are alerted to decide.',
    },
];

/**
 * How a refill is decided before any rule is written.
 *
 * Most panels say it in the name ("365 Days Refill", "R30", "No Refill"), so
 * the bot reads that. The default below covers a service that says nothing.
 */
function AutomaticCard({ policy }: { policy: RefillPolicy }) {
    const form = useForm({ autoRead: policy.autoRead, default: policy.default });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('support-bot.refill-policy'), { preserveScroll: true });
    };

    return (
        <Card
            title="Automatic"
            description="Your rules below come first. If none matches, the bot reads what the service itself says."
        >
            <form onSubmit={submit} className="space-y-4">
                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        checked={form.data.autoRead}
                        onChange={(event) => form.setData('autoRead', event.target.checked)}
                        className="mt-1 size-4"
                    />
                    <span className="text-sm">
                        <span className="font-semibold">Read the refill promise from the service</span>
                        <span className="block text-muted-foreground">
                            “30 Days Refill”, “R365” and “Lifetime” allow it; “No Refill” refuses.
                            It also checks the order is still inside those days.
                        </span>
                    </span>
                </label>

                <Field label="When the service says nothing">
                    <select
                        className={inputClass}
                        value={form.data.default}
                        onChange={(event) =>
                            form.setData('default', event.target.value as RefillPolicy['default'])
                        }
                    >
                        {DEFAULT_CHOICES.map((choice) => (
                            <option key={choice.value} value={choice.value}>
                                {choice.label}
                            </option>
                        ))}
                    </select>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {DEFAULT_CHOICES.find((choice) => choice.value === form.data.default)?.note}
                    </p>
                </Field>

                <button
                    type="submit"
                    disabled={form.processing}
                    className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-60"
                >
                    {form.processing ? 'Saving…' : 'Save'}
                </button>
            </form>
        </Card>
    );
}

function RuleGroup({ title, note, rules }: { title: string; note?: string; rules: Rule[] }) {
    return (
        <section>
            <div className="mb-2.5">
                <h2 className="font-heading text-sm font-bold uppercase tracking-wide text-muted-foreground">
                    {title}
                </h2>
                {note && <p className="text-xs text-muted-foreground">{note}</p>}
            </div>

            <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                {rules.map((rule) => (
                    <RuleRow key={rule.id} rule={rule} />
                ))}
            </ul>
        </section>
    );
}

function AddRuleForm({ panels, onCancel }: { panels: PanelOption[]; onCancel?: () => void }) {
    const form = useForm({
        type: 'guarantee' as 'guarantee' | 'no_guarantee',
        keyword: '',
        refillDays: 30 as number | string,
        panelId: '' as number | string,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('support-bot.rules.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('keyword'),
        });
    };

    return (
        <Card title="Add a rule">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Rule">
                        <select
                            className={inputClass}
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData(
                                    'type',
                                    event.target.value as 'guarantee' | 'no_guarantee',
                                )
                            }
                        >
                            <option value="guarantee">Refill guaranteed</option>
                            <option value="no_guarantee">No refill</option>
                        </select>
                    </Field>

                    {form.data.type === 'guarantee' && (
                        <Field
                            label="Refill days"
                            hint="0 means lifetime."
                            error={form.errors.refillDays}
                        >
                            <input
                                type="number"
                                min={0}
                                max={3650}
                                className={inputClass}
                                value={form.data.refillDays}
                                onChange={(event) =>
                                    form.setData('refillDays', event.target.value)
                                }
                            />
                        </Field>
                    )}
                </div>

                <Field
                    label="Keyword"
                    hint="Matched inside the service name, ignoring capitals."
                    error={form.errors.keyword}
                >
                    <input
                        className={inputClass}
                        value={form.data.keyword}
                        onChange={(event) => form.setData('keyword', event.target.value)}
                        placeholder="instagram followers"
                        required
                    />
                </Field>

                {panels.length > 0 && (
                    <Field label="Panel" hint="Leave on “All panels” to apply everywhere.">
                        <select
                            className={inputClass}
                            value={form.data.panelId}
                            onChange={(event) => form.setData('panelId', event.target.value)}
                        >
                            <option value="">All panels</option>
                            {panels.map((panel) => (
                                <option key={panel.id} value={panel.id}>
                                    {panel.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}

                <div className="flex flex-col gap-2 sm:flex-row-reverse">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-60"
                    >
                        {form.processing ? 'Adding…' : 'Add rule'}
                    </button>

                    {onCancel && (
                        <button
                            type="button"
                            onClick={onCancel}
                            className="rounded-xl px-5 py-2.5 text-sm text-muted-foreground hover:text-foreground"
                        >
                            Cancel
                        </button>
                    )}
                </div>
            </form>
        </Card>
    );
}

function RuleRow({ rule }: { rule: Rule }) {
    const [busy, setBusy] = useState(false);
    const active = rule.status === 'active';

    const toggle = () => {
        setBusy(true);
        router.patch(
            route('support-bot.rules.update', rule.id),
            { status: active ? 'inactive' : 'active' },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const remove = () => {
        if (!window.confirm(`Remove the rule for “${rule.keyword}”?`)) {
            return;
        }

        setBusy(true);
        router.delete(route('support-bot.rules.destroy', rule.id), {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const cover =
        rule.type === 'no_guarantee'
            ? 'No refill'
            : rule.refillDays === 0
              ? 'Lifetime'
              : `${rule.refillDays} days`;

    return (
        <li className="flex items-center gap-3 p-3.5">
            <div className="min-w-0 flex-1">
                <p
                    className={`font-data truncate text-sm ${
                        active ? '' : 'text-muted-foreground line-through'
                    }`}
                >
                    {rule.keyword}
                </p>
                <p className="text-xs text-muted-foreground">
                    {cover}
                    {rule.panelName && ` · ${rule.panelName}`}
                </p>
                {rule.shadowed && (
                    <p className="text-xs text-amber-500">
                        Never used: an earlier rule has the same keyword. Remove one of them.
                    </p>
                )}
            </div>

            {/* A switch rather than a "Disable" button: the row says whether
                it is on, and the same control turns it back. */}
            <label className="relative inline-flex h-6 w-11 shrink-0 cursor-pointer">
                <input
                    type="checkbox"
                    checked={active}
                    onChange={toggle}
                    disabled={busy}
                    className="peer sr-only"
                    aria-label={`${active ? 'Disable' : 'Enable'} rule ${rule.keyword}`}
                />
                <span className="absolute inset-0 rounded-full bg-muted-foreground/30 transition-colors peer-checked:bg-primary peer-disabled:opacity-60" />
                <span className="absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5" />
            </label>

            <button
                type="button"
                onClick={remove}
                disabled={busy}
                className="shrink-0 rounded-lg p-2 text-muted-foreground transition-colors hover:text-destructive disabled:opacity-60"
                aria-label={`Remove rule ${rule.keyword}`}
            >
                <Trash2 className="size-4" aria-hidden />
            </button>
        </li>
    );
}
