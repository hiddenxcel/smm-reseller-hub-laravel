import { useForm } from '@inertiajs/react';
import { Check, Gift, Loader2 } from 'lucide-react';
import { ReactNode, useMemo, useState } from 'react';
import { Field, inputClass } from '../OrderBot/bits';
import { BillingPageProps } from './types';

const money = (cents: number, currency: string) =>
    `${currency === 'USD' ? '$' : ''}${(cents / 100).toFixed(2)}${currency === 'USD' ? '' : ` ${currency}`}`;

/** What a reseller currently has of a service, in a few words. */
function standing(service: BillingPageProps['services'][number]): string {
    const { state, daysLeft } = service;

    if (state === 'active' && daysLeft !== null) {
        return daysLeft > 0 ? `Active · ${daysLeft} day${daysLeft === 1 ? '' : 's'} left` : 'Expires today';
    }

    if (state === 'sandbox') {
        return 'Test mode — buy to go live';
    }

    if (daysLeft !== null && daysLeft < 0) {
        return `Expired ${Math.abs(daysLeft)} day${Math.abs(daysLeft) === 1 ? '' : 's'} ago`;
    }

    return 'Not subscribed';
}

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
 *
 * One card with numbered steps rather than five cards: it reads as a single
 * purchase, and a phone scrolls past one block instead of five.
 */
export function Cart({
    services,
    terms,
    gateways,
    currency,
    credit,
    numbers,
    preselect,
}: Omit<BillingPageProps, 'invoices'>) {
    const [months, setMonths] = useState(terms[0]?.months ?? 1);

    const form = useForm({
        // A number chosen on the setup screen arrives here selected, with the
        // bot it needs, but nothing is bought until the reseller pays.
        services: preselect ? [preselect.service] : ([] as string[]),
        months: terms[0]?.months ?? 1,
        gateway: gateways[0]?.code ?? '',
        numberId: (preselect?.numberId ?? '') as number | string,
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
    const needsPhone = gateway?.needsPhone ?? gateway?.type === 'mobile';

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
            className="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <Step title="Choose a bot">
                <div className="space-y-2">
                    {services.map((service) => {
                        const price = service.termPrices[String(months)] ?? 0;
                        const chosen = form.data.services.includes(service.key);

                        return (
                            <label
                                key={service.key}
                                className={[
                                    'flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition-colors',
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
                                    <span className="flex items-baseline justify-between gap-2">
                                        <span className="font-medium">{service.name}</span>
                                        <span className="font-data shrink-0 text-sm">
                                            {money(price, currency)}
                                        </span>
                                    </span>
                                    <span className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                        {service.state === 'active' && (
                                            <Check className="size-3 text-primary" aria-hidden />
                                        )}
                                        {standing(service)}
                                    </span>
                                </span>
                            </label>
                        );
                    })}
                </div>
            </Step>

            <Step title="Period">
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {terms.map((term) => (
                        <button
                            key={term.months}
                            type="button"
                            onClick={() => setTerm(term.months)}
                            className={[
                                'rounded-xl border px-3 py-2.5 text-center transition-colors',
                                months === term.months
                                    ? 'border-primary bg-primary/5 font-semibold'
                                    : 'border-border hover:bg-accent/50',
                            ].join(' ')}
                            aria-pressed={months === term.months}
                        >
                            <span className="block text-sm">{term.label}</span>
                            <span
                                className={`block text-xs ${
                                    term.discount > 0 ? 'text-primary' : 'text-transparent'
                                }`}
                            >
                                {term.discount > 0
                                    ? `save ${Math.round(term.discount * 100)}%`
                                    : '·'}
                            </span>
                        </button>
                    ))}
                </div>
            </Step>

            {numbers.length > 0 && (
                <Step title="WhatsApp number" hint="Optional — rent one from us.">
                    <select
                        className={inputClass}
                        value={form.data.numberId}
                        onChange={(event) => form.setData('numberId', event.target.value)}
                        aria-label="WhatsApp number"
                    >
                        <option value="">No thanks — I have my own</option>
                        {numbers.map((number) => (
                            <option key={number.id} value={number.id}>
                                {number.displayNumber} — {money(number.cost, currency)}
                            </option>
                        ))}
                    </select>
                </Step>
            )}

            <Step title="Pay with">
                {gateways.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No payment method is available right now.
                    </p>
                ) : (
                    <div className="space-y-3">
                        {/* A menu, not a list of boxes: with a gateway per
                            country the boxes would run to a page of their own. */}
                        <select
                            className={inputClass}
                            value={form.data.gateway}
                            onChange={(event) => form.setData('gateway', event.target.value)}
                            aria-label="Payment method"
                        >
                            {gateways.map((option) => (
                                <option key={option.code} value={option.code}>
                                    {option.label}
                                </option>
                            ))}
                        </select>

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
                                    inputMode="tel"
                                />
                            </Field>
                        )}
                    </div>
                )}
            </Step>

            {/* The total and the button, tinted so the eye lands here last. */}
            <div className="space-y-3 bg-muted/40 p-4 sm:p-5">
                <dl className="space-y-1.5 text-sm">
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

                    <div className="flex items-baseline justify-between gap-3 border-t border-border pt-2">
                        <dt className="font-semibold">Due now</dt>
                        <dd className="font-heading text-2xl font-extrabold [font-variant-numeric:tabular-nums]">
                            {money(dueNow, currency)}
                        </dd>
                    </div>
                </dl>

                <button
                    type="submit"
                    disabled={!canPay || form.processing}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground disabled:opacity-60"
                >
                    {form.processing && <Loader2 className="size-4 animate-spin" aria-hidden />}
                    {form.processing
                        ? 'Starting…'
                        : form.data.services.length === 0
                          ? 'Pick a service first'
                          : `Pay ${money(dueNow, currency)}`}
                </button>

            </div>
        </form>
    );
}

/** One part of the purchase, divided from the next by a hairline. */
function Step({
    title,
    hint,
    children,
}: {
    title: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <section className="border-b border-border p-4 sm:p-5">
            <div className="mb-3">
                <h3 className="font-heading font-bold">{title}</h3>
                {hint && <p className="text-sm text-muted-foreground">{hint}</p>}
            </div>
            {children}
        </section>
    );
}
