import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { CircleAlert, Gift } from 'lucide-react';
import { ReactNode, useState } from 'react';
import { Cart } from './Cart';
import { BillingPageProps, Invoice } from './types';

const money = (amount: number, currency: string) =>
    `${currency === 'USD' ? '$' : ''}${amount.toFixed(2)}${currency === 'USD' ? '' : ` ${currency}`}`;

/**
 * The reseller paying us.
 *
 * Expiry leads, because a reseller opening this page is nearly always asking
 * one of two things — "when does this stop working?" or "why has it stopped?"
 * — and both are answered by a date. The purchase comes after that: you decide
 * to buy once you know what you have.
 */
export default function BillingIndex({
    services,
    terms,
    gateways,
    currency,
    credit,
    numbers,
    invoices,
}: BillingPageProps) {
    const expiring = services.filter(
        (service) => service.daysLeft !== null && service.daysLeft <= 7 && service.state === 'active',
    );

    const lapsed = services.filter((service) => service.state === 'locked');

    return (
        <AuthenticatedLayout>
            <Head title="Billing" />

            <div className="mx-auto max-w-3xl space-y-4 sm:space-y-6">
                <header className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                            Billing
                        </h1>
                    </div>

                    {credit > 0 && (
                        <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1.5 text-sm font-medium text-primary">
                            <Gift className="size-3.5" aria-hidden />
                            {money(credit, currency)} credit
                        </span>
                    )}
                </header>

                {expiring.length > 0 && (
                    <Notice tone="warning">
                        <p className="font-medium">
                            {expiring.length === 1
                                ? `${expiring[0].name} expires in ${expiring[0].daysLeft} day${expiring[0].daysLeft === 1 ? '' : 's'}`
                                : `${expiring.length} subscriptions expire within a week`}
                        </p>
                    </Notice>
                )}

                {lapsed.length > 0 && (
                    <Notice tone="danger">
                        <p className="font-medium">
                            {lapsed.map((service) => service.name).join(' and ')}{' '}
                            {lapsed.length === 1 ? 'is not running' : 'are not running'}
                        </p>
                    </Notice>
                )}

                <Cart
                        services={services}
                        terms={terms}
                        gateways={gateways}
                        currency={currency}
                        credit={credit}
                    numbers={numbers}
                />

                <Section title="Payments">
                    <Invoices invoices={invoices} />
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section>
            <h2 className="font-heading mb-2.5 text-sm font-bold uppercase tracking-wide text-muted-foreground">
                {title}
            </h2>
            {children}
        </section>
    );
}

function Notice({ tone, children }: { tone: 'warning' | 'danger'; children: ReactNode }) {
    const styles =
        tone === 'warning'
            ? 'border-[oklch(0.77_0.16_70/0.35)] bg-[oklch(0.77_0.16_70/0.08)] text-[oklch(0.55_0.15_70)]'
            : 'border-destructive/30 bg-destructive/5 text-destructive';

    return (
        <div className={`flex items-start gap-3 rounded-2xl border p-4 ${styles}`}>
            <CircleAlert className="mt-0.5 size-5 shrink-0" aria-hidden />
            <div className="min-w-0 text-foreground">{children}</div>
        </div>
    );
}

/**
 * Payment history. Pending rows are shown rather than hidden — a reseller
 * waiting on a crypto confirmation needs to see that we know about it.
 * The latest three show first; the rest are one tap away.
 */
function Invoices({ invoices }: { invoices: Invoice[] }) {
    const [showAll, setShowAll] = useState(false);

    if (invoices.length === 0) {
        return (
            <p className="rounded-2xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                Nothing bought yet. Invoices appear here, with what they bought and what they cost.
            </p>
        );
    }

    const shown = showAll ? invoices : invoices.slice(0, 3);

    return (
        <div className="overflow-hidden rounded-2xl border border-border bg-card">
            <ul className="divide-y divide-border">
                {shown.map((invoice) => (
                    <li key={invoice.id} className="flex items-center justify-between gap-3 p-4">
                        <div className="min-w-0">
                            <p className="truncate text-sm font-medium">
                                {invoice.items.join(', ') || 'Subscription'}
                                {invoice.months && (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        · {invoice.months} mo
                                    </span>
                                )}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {invoice.at && new Date(invoice.at).toLocaleDateString()}
                                {' · '}
                                {invoice.gateway}
                            </p>
                        </div>

                        <div className="shrink-0 text-right">
                            <p className="font-data text-sm">
                                {money(invoice.amount, invoice.currency)}
                            </p>
                            <span
                                className={[
                                    'text-xs font-medium',
                                    invoice.status === 'success'
                                        ? 'text-primary'
                                        : invoice.status === 'pending'
                                          ? 'text-muted-foreground'
                                          : 'text-destructive',
                                ].join(' ')}
                            >
                                <span className="capitalize">
                                    {invoice.status === 'success' ? 'Paid' : invoice.status}
                                </span>
                                {invoice.creditApplied > 0 &&
                                    ` · −${money(invoice.creditApplied, invoice.currency)} credit`}
                            </span>
                        </div>
                    </li>
                ))}
            </ul>

            {invoices.length > 3 && (
                <button
                    type="button"
                    onClick={() => setShowAll(!showAll)}
                    className="w-full border-t border-border py-3 text-sm font-medium text-primary hover:bg-accent/40"
                >
                    {showAll ? 'Show fewer' : `Show all ${invoices.length}`}
                </button>
            )}
        </div>
    );
}
