import Reveal from '@/components/landing/Reveal';
import { Section, SectionHeading } from '@/components/landing/Section';
import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Bot,
    Check,
    Headset,
    MessageSquare,
    Phone,
    Rocket,
    Sparkles,
} from 'lucide-react';

type PlanSummary = {
    name: string;
    description: string | null;
    monthly: number;
    yearly: number;
    currency: string;
};

type Props = {
    plans: Record<string, PlanSummary>;
    demoNumber: string | null;
};

/**
 * The five services at a glance, each pointing somewhere fuller.
 *
 * Deliberately shallower than Features: this page answers "what do you sell"
 * in one screen, where Features answers "does it do the thing I need" at
 * length. Repeating Features here would leave both pages saying half of it.
 */

const SERVICES = [
    {
        key: 'order_bot',
        icon: Bot,
        problem: 'You are answering order messages yourself, at every hour.',
        solves: [
            'Takes the order end to end',
            'Charges the wallet before placing it',
            'Sends it to the right panel',
        ],
    },
    {
        key: 'support_bot',
        icon: Headset,
        problem: '"Where is my order" and "it dropped" eat your evenings.',
        solves: [
            'Refills against your guarantee rules',
            'Order status read from the panel',
            'Cancel, speed up, partial complaints',
        ],
    },
    {
        key: 'ai_chat',
        icon: Sparkles,
        problem: 'Customers ask things no menu ever predicted.',
        solves: [
            'Answers in the customer’s language',
            'Runs inside the order bot',
            'Falls back to the menu for real actions',
        ],
    },
    {
        key: 'ai_tickets',
        icon: MessageSquare,
        problem: 'Not every customer comes through WhatsApp.',
        solves: [
            'A ticket desk on your own site',
            'Answers drafted automatically',
            'Escalates what it cannot settle',
        ],
    },
    {
        key: 'number_rental',
        icon: Phone,
        problem: 'You have no Meta Business account, and approval takes days.',
        solves: [
            'A Cloud API number today',
            'Same official API as your own',
            'Swap to yours when approved',
        ],
    },
];

export default function Services({ plans, demoNumber }: Props) {
    return (
        <PublicLayout
            eyebrow="Services"
            title="Five services. Take the ones you need."
            description="Each solves one problem and is sold on its own — so you are never paying for a part of the shop you do not run."
            demoNumber={demoNumber}
        >
            <Head title="Services" />

            <Section className="pt-0">
                <div className="grid gap-5 lg:grid-cols-2">
                    {SERVICES.map((service, index) => {
                        const plan = plans[service.key];

                        if (! plan) {
                            return null;
                        }

                        return (
                            <Reveal key={service.key} delay={index * 80} index={index} card className="h-full">
                                <div className="flex h-full flex-col rounded-2xl border border-border bg-card p-6 transition-all duration-300 hover:-translate-y-1 hover:border-primary/40">
                                    <div className="mb-4 flex items-start justify-between gap-4">
                                        <span className="flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                                            <service.icon className="size-5" />
                                        </span>

                                        <p className="text-right">
                                            <span className="font-heading block text-2xl font-extrabold">
                                                ${plan.monthly.toFixed(0)}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                per month
                                            </span>
                                        </p>
                                    </div>

                                    <h2 className="font-heading text-lg font-bold">{plan.name}</h2>

                                    {/* The problem before the product: a reseller
                                        recognises their own evening faster than they
                                        recognise a feature name. */}
                                    <p className="mt-2 text-sm text-pretty text-muted-foreground">
                                        {service.problem}
                                    </p>

                                    <ul className="mt-5 flex-1 space-y-2">
                                        {service.solves.map((point) => (
                                            <li key={point} className="flex items-start gap-2.5 text-sm">
                                                <Check className="mt-0.5 size-4 shrink-0 text-primary" />
                                                {point}
                                            </li>
                                        ))}
                                    </ul>

                                    <Link
                                        href={`${route('features')}#${service.key}`}
                                        className="mt-5 flex items-center gap-1.5 text-sm font-semibold text-primary transition-opacity hover:opacity-80"
                                    >
                                        See what it does
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </div>
                            </Reveal>
                        );
                    })}
                </div>
            </Section>

            <Section muted>
                <SectionHeading
                    eyebrow="Not sure where to start"
                    title="Most shops begin with the order bot"
                    subtitle="It is the one that earns while you are asleep. Support comes next, once the questions start piling up."
                />

                <Reveal className="flex flex-wrap justify-center gap-3">
                    <Button size="lg" asChild>
                        <Link href={route('register')}>
                            <Rocket className="size-4" />
                            Start free
                        </Link>
                    </Button>

                    <Button size="lg" variant="outline" asChild>
                        <Link href={route('pricing')}>Compare prices</Link>
                    </Button>
                </Reveal>
            </Section>
        </PublicLayout>
    );
}
