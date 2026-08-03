import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { Card, Field, inputClass } from '../OrderBot/bits';
import { PanelOption, Rule } from './types';

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
 */
export function RulesTab({ rules, panels }: { rules: Rule[]; panels: PanelOption[] }) {
    const exclusions = rules.filter((rule) => rule.type === 'no_guarantee');
    const grants = rules.filter((rule) => rule.type === 'guarantee');

    return (
        <div className="space-y-6">
            <AddRuleForm panels={panels} />

            {rules.length === 0 ? (
                <Card title="No rules yet">
                    <p className="text-sm text-muted-foreground">
                        Without a rule the bot refuses every refill request. Add one above —
                        a keyword like <span className="font-data">followers</span> covers
                        every service with that word in its name.
                    </p>
                </Card>
            ) : (
                <>
                    {exclusions.length > 0 && (
                        <Card
                            title="No refill"
                            description="Checked first — these win over any guarantee below."
                        >
                            <RuleList rules={exclusions} />
                        </Card>
                    )}

                    {grants.length > 0 && (
                        <Card title="Refill guaranteed">
                            <RuleList rules={grants} />
                        </Card>
                    )}
                </>
            )}
        </div>
    );
}

function AddRuleForm({ panels }: { panels: PanelOption[] }) {
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
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="Rule" hint="What happens when a service name matches.">
                    <select
                        className={inputClass}
                        value={form.data.type}
                        onChange={(event) =>
                            form.setData('type', event.target.value as 'guarantee' | 'no_guarantee')
                        }
                    >
                        <option value="guarantee">Refill guaranteed</option>
                        <option value="no_guarantee">No refill</option>
                    </select>
                </Field>

                <Field
                    label="Keyword"
                    hint="Matched inside the service name, case-insensitively."
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
                            onChange={(event) => form.setData('refillDays', event.target.value)}
                        />
                    </Field>
                )}

                {panels.length > 0 && (
                    <Field label="Panel" hint="Leave blank to apply to every panel.">
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

                <div className="sm:col-span-2">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                    >
                        {form.processing ? 'Adding…' : 'Add rule'}
                    </button>
                </div>
            </form>
        </Card>
    );
}

function RuleList({ rules }: { rules: Rule[] }) {
    return (
        <ul className="divide-y divide-border">
            {rules.map((rule) => (
                <RuleRow key={rule.id} rule={rule} />
            ))}
        </ul>
    );
}

function RuleRow({ rule }: { rule: Rule }) {
    const [busy, setBusy] = useState(false);

    const toggle = () => {
        setBusy(true);
        router.patch(
            route('support-bot.rules.update', rule.id),
            { status: rule.status === 'active' ? 'inactive' : 'active' },
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
        <li className="flex flex-wrap items-center gap-3 py-3">
            <div className="min-w-0 flex-1">
                <p
                    className={`font-data truncate text-sm ${
                        rule.status === 'active' ? '' : 'text-muted-foreground line-through'
                    }`}
                >
                    {rule.keyword}
                </p>
                <p className="text-xs text-muted-foreground">
                    {cover}
                    {rule.panelName && ` · ${rule.panelName}`}
                </p>
            </div>

            <button
                type="button"
                onClick={toggle}
                disabled={busy}
                className="rounded-lg border border-input px-3 py-1.5 text-xs disabled:opacity-60"
            >
                {rule.status === 'active' ? 'Disable' : 'Enable'}
            </button>

            <button
                type="button"
                onClick={remove}
                disabled={busy}
                className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:text-destructive disabled:opacity-60"
                aria-label={`Remove rule ${rule.keyword}`}
            >
                <Trash2 className="size-4" aria-hidden />
            </button>
        </li>
    );
}
