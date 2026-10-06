import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { Smartphone } from 'lucide-react';
import { Field, inputClass } from './bits';
import { FamilyState } from './types';

/**
 * A provider that is one account serving several markets (FimiPay, Snippe) is
 * one card: the keys go in once and each market is a switch.
 *
 * Saving writes the keys to every market behind the scenes, which is why the
 * rest of the app (checkout, the default gateway) still sees a gateway per
 * market and needs no special case.
 */
export function FamilyCard({ family }: { family: FamilyState }) {
    const form = useForm({
        credentials: { api_key: '', webhook_secret: '' },
        markets: family.markets.filter((market) => market.on).map((market) => market.code),
    });

    const toggle = (code: string) =>
        form.setData(
            'markets',
            form.data.markets.includes(code)
                ? form.data.markets.filter((current) => current !== code)
                : [...form.data.markets, code],
        );

    return (
        <section className="rounded-xl border border-border bg-card p-5">
            <header className="flex items-start gap-3">
                <span
                    className="grid size-10 shrink-0 place-items-center rounded-xl bg-muted"
                    aria-hidden
                >
                    <Smartphone className="size-4 text-muted-foreground" />
                </span>

                <div className="min-w-0 flex-1">
                    <h3 className="font-heading font-bold">{family.label}</h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">{family.intro}</p>
                </div>
            </header>

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('order-bot.gateways.family', family.family), {
                        preserveScroll: true,
                        onSuccess: () => form.setData('credentials', { api_key: '', webhook_secret: '' }),
                    });
                }}
                className="mt-4 space-y-4 border-t border-border pt-4"
            >
                <Field
                    label={family.keyLabel}
                    hint={family.keySaved ? 'Saved — leave blank to keep it' : undefined}
                    error={form.errors['credentials.api_key' as keyof typeof form.errors]}
                >
                    <input
                        type="password"
                        value={form.data.credentials.api_key}
                        onChange={(event) =>
                            form.setData('credentials', {
                                ...form.data.credentials,
                                api_key: event.target.value,
                            })
                        }
                        placeholder={family.keySaved ? '••••••••' : ''}
                        autoComplete="off"
                        className={inputClass}
                    />
                </Field>

                <Field
                    label={family.secretLabel}
                    hint={family.webhookSecretSaved ? 'Saved — leave blank to keep it' : undefined}
                    error={form.errors['credentials.webhook_secret' as keyof typeof form.errors]}
                >
                    <input
                        type="password"
                        value={form.data.credentials.webhook_secret}
                        onChange={(event) =>
                            form.setData('credentials', {
                                ...form.data.credentials,
                                webhook_secret: event.target.value,
                            })
                        }
                        placeholder={family.webhookSecretSaved ? '••••••••' : ''}
                        autoComplete="off"
                        className={inputClass}
                    />
                </Field>

                <div>
                    <p className="mb-2 text-xs font-medium text-muted-foreground">
                        Markets your customers can pay in
                    </p>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {family.markets.map((market) => {
                            const on = form.data.markets.includes(market.code);

                            return (
                                <label
                                    key={market.code}
                                    className={[
                                        'flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm transition-colors',
                                        on ? 'border-primary bg-primary/5' : 'border-border',
                                    ].join(' ')}
                                >
                                    <input
                                        type="checkbox"
                                        checked={on}
                                        onChange={() => toggle(market.code)}
                                        className="size-4 accent-primary"
                                    />
                                    <span className="min-w-0 flex-1 truncate">{market.label}</span>
                                    {market.isDefault && on && (
                                        <span className="text-xs font-medium text-primary">Default</span>
                                    )}
                                </label>
                            );
                        })}
                    </div>
                </div>

                <div className="flex justify-end">
                    <Button type="submit" size="sm" disabled={form.processing}>
                        {form.processing ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </form>
        </section>
    );
}
