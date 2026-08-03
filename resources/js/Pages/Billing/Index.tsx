import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { CircleAlert, Gift } from 'lucide-react';
import { Card } from '../OrderBot/bits';
import { Cart } from './Cart';
import { BillingPageProps, Invoice, SellableService } from './types';

const money = (amount: number, currency: string) =>
    `${currency === 'USD' ? '$' : ''}${amount.toFixed(2)}${currency === 'USD' ? '' : ` ${currency}`}`;

/**
 * The reseller paying us.
 *
 * Expiry leads, because a reseller opening this page is nearly always asking
 * one of two things — "when does this stop working?" or "why has it stopped?"
 * — and both are answered by a date. The cart comes after that: you decide to
 * buy once you know what you have.
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
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-bold">Billing</h1>
                        <p className="text-sm text-muted-foreground">
                            Your subscription to the platform — separate from what your own
                            customers pay you.
                        </p>
                    </div>

                    {credit > 0 && (
                        <span className="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1.5 text-sm font-medium text-primary">
                            <Gift className="size-3.5" aria-hidden />
                            {money(credit, currency)} credit
                        </span>
                    )}
                </div>
            }
        >
            <Head title="Billing" />

            <div className="space-y-6">
                {expiring.length > 0 && (
                    <div className="flex items-start gap-3 rounded-xl border border-[oklch(0.77_0.16_70/0.35)] bg-[oklch(0.77_0.16_70/0.08)] p-4">
                        <CircleAlert
                            className="mt-0.5 size-5 shrink-0 text-[oklch(0.55_0.15_70)]"
                            aria-hidden
                        />
                        <div>
                            <p className="font-medium">
                                {expiring.length === 1
                                    ? `${expiring[0].name} expires in ${expiring[0].daysLeft} day${expiring[0].daysLeft === 1 ? '' : 's'}`
                                    : `${expiring.length} subscriptions expire within a week`}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Renew below and the new term is added to what is left — you
                                lose nothing by paying early.
                            </p>
                        </div>
                    </div>
                )}

                {lapsed.length > 0 && (
                    <div className="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4">
                        <CircleAlert className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden />
                        <div>
                            <p className="font-medium">
                                {lapsed.map((service) => service.name).join(' and ')}{' '}
                                {lapsed.length === 1 ? 'is not running' : 'are not running'}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Messages to these bots get a "currently paused" reply until a
                                subscription is active.
                            </p>
                        </div>
                    </div>
                )}

                <section>
                    <h2 className="font-heading mb-3 font-bold">What you have</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {services.map((service) => (
                            <ServiceCard key={service.key} service={service} />
                        ))}
                    </div>
                </section>

                <section>
                    <h2 className="font-heading mb-3 font-bold">Buy or renew</h2>
                    <Cart
                        services={services}
                        terms={terms}
                        gateways={gateways}
                        currency={currency}
                        credit={credit}
                        numbers={numbers}
                    />
                </section>

                <section>
                    <h2 className="font-heading mb-3 font-bold">Invoices</h2>
                    <Invoices invoices={invoices} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function ServiceCard({ service }: { service: SellableService }) {
    const { state, daysLeft, endsAt } = service;

    const tone =
        state === 'active'
            ? 'bg-primary/10 text-primary'
            : state === 'sandbox'
              ? 'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]'
              : 'bg-muted text-muted-foreground';

    const label = state === 'active' ? 'Active' : state === 'sandbox' ? 'Sandbox' : 'Not running';

    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <h3 className="font-medium">{service.name}</h3>
                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${tone}`}>
                    {label}
                </span>
            </div>

            <p className="mt-2 text-sm text-muted-foreground">
                {state === 'active' && daysLeft !== null ? (
                    daysLeft > 0 ? (
                        <>
                            {daysLeft} day{daysLeft === 1 ? '' : 's'} left
                            {endsAt && ` · until ${new Date(endsAt).toLocaleDateString()}`}
                        </>
                    ) : (
                        'Expires today'
                    )
                ) : state === 'sandbox' ? (
                    'Answering your test numbers only. Buy it to go live.'
                ) : daysLeft !== null && daysLeft < 0 ? (
                    `Expired ${Math.abs(daysLeft)} day${Math.abs(daysLeft) === 1 ? '' : 's'} ago`
                ) : (
                    'Never subscribed'
                )}
            </p>
        </div>
    );
}

/**
 * Payment history. Pending rows are shown rather than hidden — a reseller
 * waiting on a crypto confirmation needs to see that we know about it.
 */
function Invoices({ invoices }: { invoices: Invoice[] }) {
    if (invoices.length === 0) {
        return (
            <Card title="No invoices yet">
                <p className="text-sm text-muted-foreground">
                    Anything you buy appears here, with what it bought and what it cost.
                </p>
            </Card>
        );
    }

    return (
        <div className="overflow-hidden rounded-xl border border-border bg-card">
            <ul className="divide-y divide-border">
                {invoices.map((invoice) => (
                    <li key={invoice.id} className="flex flex-wrap items-center gap-3 p-4">
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium">
                                {invoice.items.join(', ') || 'Subscription'}
                                {invoice.months && (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        · {invoice.months} month{invoice.months === 1 ? '' : 's'}
                                    </span>
                                )}
                            </p>
                            <p className="font-data mt-0.5 text-xs text-muted-foreground">
                                {invoice.reference} · {invoice.gateway}
                                {invoice.at && ` · ${new Date(invoice.at).toLocaleDateString()}`}
                            </p>
                        </div>

                        <div className="text-right">
                            <p className="font-data text-sm">
                                {money(invoice.amount, invoice.currency)}
                            </p>
                            {invoice.creditApplied > 0 && (
                                <p className="text-xs text-primary">
                                    −{money(invoice.creditApplied, invoice.currency)} credit
                                </p>
                            )}
                        </div>

                        <span
                            className={[
                                'shrink-0 rounded px-2 py-0.5 text-[11px] font-medium capitalize',
                                invoice.status === 'success'
                                    ? 'bg-primary/10 text-primary'
                                    : invoice.status === 'pending'
                                      ? 'bg-muted text-muted-foreground'
                                      : 'bg-destructive/10 text-destructive',
                            ].join(' ')}
                        >
                            {invoice.status === 'success' ? 'Paid' : invoice.status}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
