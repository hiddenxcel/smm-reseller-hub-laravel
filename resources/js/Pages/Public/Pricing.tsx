import Faq from '@/components/landing/Faq';
import PaymentRail from '@/components/landing/PaymentRail';
import PricingTable from '@/components/landing/PricingTable';
import Reveal from '@/components/landing/Reveal';
import { Section, SectionHeading } from '@/components/landing/Section';
import PublicLayout from '@/Layouts/PublicLayout';
import { Head } from '@inertiajs/react';
import Seo from '@/components/Seo';
import { Check, X } from 'lucide-react';

type PlanSummary = {
    name: string;
    description: string | null;
    monthly: number;
    yearly: number;
    currency: string;
};

type GatewaySummary = {
    code: string;
    label: string;
    type: string;
};

type Props = {
    plans: Record<string, PlanSummary>;
    gateways: GatewaySummary[];
    demoNumber: string | null;
};

/**
 * Every service and its price.
 *
 * Unlike the landing page's three, this shows all five: someone who has
 * clicked Pricing has come to compare, so leaving two out would be hiding the
 * answer they came for.
 */

const INCLUDED = [
    'Unlimited customers and orders',
    'Sandbox until you choose to go live',
    'Your own payment gateways',
    'All five bot languages',
    'API access and logs',
    'Cancel any time',
];

const NOT_INCLUDED = [
    { text: 'A cut of what your customers spend', note: 'The money never passes through us' },
    { text: 'Per-message or per-order fees', note: 'Meta bills conversations, not us' },
    { text: 'A setup fee or a contract', note: 'Monthly, and you can stop' },
];

const PRICING_FAQS = [
    {
        q: 'Do I pay per order or per message?',
        a: 'No. You pay a flat monthly price for each service you switch on, however many orders come through it. Meta charges for WhatsApp conversations on its own terms, and that is between you and Meta.',
    },
    {
        q: 'Do you take a percentage of my sales?',
        a: 'No. Your customers pay into your own gateway accounts, and the money never passes through us. We are paid for the bot, not for what it sells.',
    },
    {
        q: 'What happens if I stop paying for a service?',
        a: 'That service stops running. Your data stays — orders, customers, wallet balances — so switching it back on picks up where it left off.',
    },
    {
        q: 'Can I change between monthly and yearly?',
        a: 'Yes. Yearly is the same service billed twelve months at once for 20% less; switching takes effect from your next renewal.',
    },
    {
        q: 'Is there a free trial?',
        a: 'Every service starts in sandbox, free. You can connect your panel, build the bot and test it against your own number before paying anything.',
    },
];

export default function Pricing({ plans, gateways, demoNumber }: Props) {
    return (
        <PublicLayout
            eyebrow="Pricing"
            title="Pay for what you use, nothing else"
            description="Every service is sold on its own. No commission, no per-order fee, no contract."
            demoNumber={demoNumber}
        >
            <Seo
                title="Pricing"
                description="Flat monthly pricing per service, 20% off yearly. No commission on your sales, no per-order fee and no contract — your customers pay into your own gateway accounts."
            />

            <Section className="pt-0">
                <PricingTable plans={plans} />
            </Section>

            {/* ---- what is and is not in the price ---- */}
            <Section muted>
                <SectionHeading
                    eyebrow="What the price covers"
                    title="Included in every service"
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Reveal>
                        <div className="h-full rounded-2xl border border-primary/25 bg-primary/5 p-6">
                            <p className="font-heading mb-4 font-bold text-primary">
                                What you get
                            </p>

                            <ul className="space-y-3">
                                {INCLUDED.map((item) => (
                                    <li key={item} className="flex items-start gap-3 text-sm">
                                        <Check className="mt-0.5 size-4 shrink-0 text-primary" />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </Reveal>

                    <Reveal delay={120}>
                        <div className="h-full rounded-2xl border border-border bg-card p-6">
                            <p className="font-heading mb-4 font-bold">
                                What you will never be charged
                            </p>

                            <ul className="space-y-3">
                                {NOT_INCLUDED.map((item) => (
                                    <li key={item.text} className="flex items-start gap-3">
                                        <X className="mt-0.5 size-4 shrink-0 text-destructive/60" />
                                        <span>
                                            <span className="block text-sm line-through decoration-destructive/40">
                                                {item.text}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {item.note}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </Reveal>
                </div>
            </Section>

            {/* ---- how your customers pay you ---- */}
            <Section>
                <SectionHeading
                    eyebrow="Your customers"
                    title="They pay you, through your own accounts"
                    subtitle="Connect the gateways you already use. We never hold the money."
                />

                <PaymentRail gateways={gateways} />
            </Section>

            {/* ---- pricing questions ---- */}
            <Section muted>
                <SectionHeading eyebrow="Pricing FAQ" title="Before you decide" />

                <Faq items={PRICING_FAQS} />
            </Section>
        </PublicLayout>
    );
}
