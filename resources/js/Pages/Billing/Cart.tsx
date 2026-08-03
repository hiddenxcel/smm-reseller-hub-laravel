import { useForm } from '@inertiajs/react';
import { Gift, Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Card, Field, inputClass } from '../OrderBot/bits';
import { BillingPageProps } from './types';

const money = (cents: number, currency: string) =>
    `${currency === 'USD' ? '$' : ''}${(cents / 100).toFixed(2)}${currency === 'USD' ? '' : ` ${currency}`}`;

/**
 * Pick services, pick a term, pay.
 *
 * The total is computed from prices the server sent for every term, so
 * changing the term is instant and still cannot disagree with what will be
 * charged — the server prices the cart again at checkout, and this is only
 * ever showing what it already said.
 *
 * Referral credit is shown as an estimate for the same reason: the real
 * deduction is a conditional UPDATE at checkout, because two tabs open at once
 * must not both spend the same credit.
 */
export function Cart({
    services,
    terms,
    gateways,
    currency,
    credit,
    numbers,
}: Omit<BillingPageProps, 'invoices'>) {
    const [months, setMonths] = useState(terms[0]?.months ?? 1);

    const form = useForm({
        services: [] as string[],
        months: terms[0]?.months ?? 1,
        gateway: gateways[0]?.code ?? '',
        numberId: '' as number | string,
        phone: '',
    });

    const toggle = (key: string) => {
        const next = form.data.services.includes(key)
            ? form.data.services.filter((s) => s !== key)
            : [...form.data.services, key];

        form.setData('services', next);
    };

    const setTerm = (value: number) => {
        setMonths(value);
        form.setData('months', value);
    };

    const chosenNumber = numbers.find((n) => String(n.id) === String(form.data.numberId));

    const subtotal = useMemo(() => {
        const fromServices = form.data.services.reduce((sum, key) => {
            const service = services.find((s) => s.key === key);

            return sum + (service?.termPrices[String(months)] ?? 0);
        }, 0);

        return fromServices + (chosenNumber?.cost ?? 0);
    }, [form.data.services, months, services, chosenNumber]);

    // Credit never takes the bill to zero: there has to be a real transaction
    // for the gateway to confirm, or a subscription would activate on a
    // payment that never happened. The floor mirrors billing.minimum_charge.
    const creditCents = Math.round(credit * 100);
    const applied = Math.max(0, Math.min(creditCents, subtotal - 100));
    const dueNow = subtotal - applied;

    const gateway = gateways.find((g) => g.code === form.data.gateway);
    const needsPhone = gateway?.type === 'mobile';

    const canPay =
        form.data.services.length > 0 &&
        gateways.length > 0 &&
        (!needsPhone || form.data.phone.trim() !== '');

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('billing.checkout'), { preserveScroll: true });
            }}
            className="space-y-6"
        >
            <Card
                title="What do you need?"
                description="Each bot is bought on its own. Pick one or both."
            >
                <div className="space-y-2">
                    {services.map((service) => {
                        const price = service.termPrices[String(months)] ?? 0;
                        const chosen = form.data.services.includes(service.key);

                        return (
                            <label
                                key={service.key}
                                className={[
                                    'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors',
                                    chosen
                                        ? 'border-primary bg-primary/5'
                                        : 'border-border hover:bg-accent/50',
                                ].join(' ')}
                            >
                                <input
                                    type="checkbox"
                                    checked={chosen}
                                    onChange={() => toggle(service.key)}
                                    className="mt-1 size-4 shrink-0 rounded border-input text-primary focus:ring-ring"
                                />

                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="font-medium">{service.name}</span>
                                        <span className="font-data text-sm">
                                            {money(price, currency)}
                                        </span>
                                    </span>
                                    {service.description && (
                                        <span className="mt-0.5 block text-sm text-muted-foreground">
                                            {service.description}
                                        </span>
                                    )}
                                    {service.state === 'active' && (
                                        <span className="mt-1 inline-block text-xs text-primary">
                                            Already active — this extends it
                                        </span>
                                    )}
                                </span>
                            </label>
                        );
                    })}
                </div>
            </Card>

            <Card title="For how long?" description="Longer terms cost less per month.">
                <div className="grid gap-2 sm:grid-cols-4">
                    {terms.map((term) => (
                        <button
                            key={term.months}
                            type="button"
                            onClick={() => setTerm(term.months)}
                            className={[
                                'rounded-xl border p-3 text-center transition-colors',
                                months === term.months
                                    ? 'border-primary bg-primary/5 font-semibold'
                                    : 'border-border hover:bg-accent/50',
                            ].join(' ')}
                            aria-pressed={months === term.months}
                        >
                            <span className="block text-sm">{term.label}</span>
                            {term.discount > 0 && (
                                <span className="mt-0.5 block text-xs text-primary">
                                    save {Math.round(term.discount * 100)}%
                                </span>
                            )}
                        </button>
                    ))}
                </div>
            </Card>

            {numbers.length > 0 && (
                <Card
                    title="Need a WhatsApp number?"
                    description="Rent one from us instead of setting up Meta yourself. Bought outright — the term above does not discount it."
                >
                    <Field label="Number">
                        <select
                            className={inputClass}
                            value={form.data.numberId}
                            onChange={(event) => form.setData('numberId', event.target.value)}
                        >
                            <option value="">No thanks — I have my own</option>
                            {numbers.map((number) => (
                                <option key={number.id} value={number.id}>
                                    {number.displayNumber} — {money(number.cost, currency)}
                                </option>
                            ))}
                        </select>
                    </Field>
                </Card>
            )}

            <Card title="How would you like to pay?">
                {gateways.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No payment method is available yet. Once the platform's gateway keys
                        are set, they appear here — nothing else on this page has to change.
                    </p>
                ) : (
                    <div className="space-y-3">
                        <div className="grid gap-2 sm:grid-cols-2">
                            {gateways.map((option) => (
                                <label
                                    key={option.code}
                                    className={[
                                        'flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors',
                                        form.data.gateway === option.code
                                            ? 'border-primary bg-primary/5'
                                            : 'border-border hover:bg-accent/50',
                                    ].join(' ')}
                                >
                                    <input
                                        type="radio"
                                        name="gateway"
                                        checked={form.data.gateway === option.code}
                                        onChange={() => form.setData('gateway', option.code)}
                                        className="size-4 shrink-0 border-input text-primary focus:ring-ring"
                                    />
                                    <span className="text-sm font-medium">{option.label}</span>
                                </label>
                            ))}
                        </div>

                        {needsPhone && (
                            <Field
                                label="Phone number"
                                hint="The payment prompt is sent to this handset."
                                error={form.errors.phone}
                            >
                                <input
                                    className={inputClass}
                                    value={form.data.phone}
                                    onChange={(event) => form.setData('phone', event.target.value)}
                                    placeholder="255712345678"
                                />
                            </Field>
                        )}
                    </div>
                )}
            </Card>

            <Card title="Total">
                <dl className="space-y-2 text-sm">
                    <div className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">Subtotal</dt>
                        <dd className="font-data">{money(subtotal, currency)}</dd>
                    </div>

                    {applied > 0 && (
                        <div className="flex justify-between gap-3 text-primary">
                            <dt className="inline-flex items-center gap-1.5">
                                <Gift className="size-3.5" aria-hidden />
                                Referral credit
                            </dt>
                            <dd className="font-data">−{money(applied, currency)}</dd>
                        </div>
                    )}

                    <div className="flex justify-between gap-3 border-t border-border pt-2 text-base font-semibold">
                        <dt>Due now</dt>
                        <dd className="font-data">{money(dueNow, currency)}</dd>
                    </div>
                </dl>

                {creditCents > 0 && applied < creditCents && (
                    <p className="mt-3 text-xs text-muted-foreground">
                        You have {money(creditCents, currency)} in credit. It cannot cover a
                        bill entirely — {money(100, currency)} has to go through the payment
                        provider for it to confirm.
                    </p>
                )}

                <button
                    type="submit"
                    disabled={!canPay || form.processing}
                    className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground disabled:opacity-60"
                >
                    {form.processing && <Loader2 className="size-4 animate-spin" aria-hidden />}
                    {form.processing
                        ? 'Starting…'
                        : form.data.services.length === 0
                          ? 'Pick a service first'
                          : `Pay ${money(dueNow, currency)}`}
                </button>

                <p className="mt-2 text-center text-xs text-muted-foreground">
                    Nothing is charged until the payment provider confirms it.
                </p>
            </Card>
        </form>
    );
}
