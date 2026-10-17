import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { LockOpen } from 'lucide-react';
import { Cart } from '../Billing/Cart';
import { BillingPageProps } from '../Billing/types';

type BoostsProps = Pick<BillingPageProps, 'terms' | 'gateways' | 'currency' | 'credit'> & {
    boosts: BillingPageProps['services'];
    /** The name the bot signs with right now. */
    brandName: string;
    /** The name it will sign with once Custom brand is bought. */
    businessName: string;
};

/**
 * The shelf of paid extras for the order bot.
 *
 * A boost is an ordinary subscription, so the purchase is the same cart the
 * Billing page uses — only the list on offer differs. New boosts appear here
 * when they are added to config billing.features.
 */
export default function Boosts({ boosts, terms, gateways, currency, credit, brandName, businessName }: BoostsProps) {
    const branded = boosts.some((boost) => boost.key === 'custom_brand' && boost.state === 'active');

    return (
        <AuthenticatedLayout>
            <Head title="Unlock" />

            <div className="mx-auto max-w-3xl space-y-4 sm:space-y-6">
                <header>
                    <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">Unlock</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Extra features for your bot. Pay once and it is yours for good.
                    </p>
                </header>

                <section className="rounded-2xl border border-border bg-card p-4">
                    <h2 className="font-heading mb-2.5 flex items-center gap-1.5 text-sm font-bold uppercase tracking-wide text-muted-foreground">
                        <LockOpen className="size-3.5" aria-hidden /> Your bot's footer
                    </h2>

                    <div className="space-y-2 text-sm">
                        <div className="rounded-xl bg-muted/50 p-3">
                            <p className="text-xs text-muted-foreground">Customers see now</p>
                            <p className="font-medium">© {brandName}</p>
                        </div>
                        {!branded && (
                            <div className="rounded-xl border border-primary/30 bg-primary/5 p-3">
                                <p className="text-xs text-muted-foreground">With Your own brand name</p>
                                <p className="font-medium">© {businessName}</p>
                            </div>
                        )}
                    </div>
                </section>

                <Cart
                    title="Choose a feature"
                    services={boosts}
                    terms={terms}
                    gateways={gateways}
                    currency={currency}
                    credit={credit}
                    numbers={[]}
                    preselect={null}
                />
            </div>
        </AuthenticatedLayout>
    );
}
