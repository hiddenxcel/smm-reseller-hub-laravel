import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Bot, Check, Headset, MessageSquare, Phone, Sparkles } from 'lucide-react';
import { useState } from 'react';
import Reveal from './Reveal';

type PlanSummary = {
    name: string;
    description: string | null;
    monthly: number;
    yearly: number;
    currency: string;
};

const ICONS: Record<string, typeof Bot> = {
    order_bot: Bot,
    support_bot: Headset,
    ai_tickets: MessageSquare,
    ai_chat: Sparkles,
    number_rental: Phone,
};

/**
 * The a la carte price list, with a monthly/yearly switch.
 *
 * The 20% yearly discount was a sentence in the subtitle and a second line of
 * small print on every card — so the saving was stated three times and shown
 * nowhere. A switch makes it a number the reader can act on.
 *
 * The order bot is marked as where to start. Not a paid tier or an upsell:
 * every other service either feeds it or answers for it, and a reseller
 * choosing between five identical cards has been given no help at all.
 */
export default function PricingTable({
    plans,
}: {
    plans: Record<string, PlanSummary>;
}) {
    const [yearly, setYearly] = useState(false);
    const entries = Object.entries(plans);

    return (
        <div>
            {/* ---- billing switch ---- */}
            <Reveal className="mb-10 flex justify-center">
                <div
                    role="group"
                    aria-label="Billing period"
                    className="inline-flex items-center rounded-full border border-border bg-card p-1"
                >
                    <button
                        type="button"
                        onClick={() => setYearly(false)}
                        aria-pressed={! yearly}
                        className={[
                            'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                            yearly
                                ? 'text-muted-foreground hover:text-foreground'
                                : 'bg-primary text-primary-foreground',
                        ].join(' ')}
                    >
                        Monthly
                    </button>

                    <button
                        type="button"
                        onClick={() => setYearly(true)}
                        aria-pressed={yearly}
                        className={[
                            'flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                            yearly
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        ].join(' ')}
                    >
                        Yearly
                        {/* 10px was below the point where small text stays
                            readable on a phone, and this is the number the
                            switch exists to advertise. */}
                        <span
                            className={[
                                'rounded-full px-1.5 py-0.5 text-xs font-bold',
                                yearly ? 'bg-white/20' : 'bg-primary/10 text-primary',
                            ].join(' ')}
                        >
                            −20%
                        </span>
                    </button>
                </div>
            </Reveal>

            {/* ---- cards ---- */}
            <div className="mx-auto grid max-w-5xl gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {entries.map(([key, plan], index) => {
                    const Icon = ICONS[key] ?? Bot;
                    const featured = key === 'order_bot';

                    // Shown per month either way, so the two states compare
                    // directly instead of asking the reader to divide by 12.
                    const perMonth = yearly ? plan.yearly / 12 : plan.monthly;

                    return (
                        <Reveal key={key} delay={index * 80} index={index} card className="h-full">
                            <div
                                className={[
                                    'relative flex h-full flex-col rounded-2xl p-6 transition-transform duration-300 hover:-translate-y-1',
                                    featured
                                        ? 'border-2 border-primary bg-card shadow-lg'
                                        : 'soft border border-transparent bg-card',
                                ].join(' ')}
                            >
                                {featured && (
                                    <span className="absolute -top-3 left-6 rounded-full bg-primary px-3 py-1 text-xs font-bold text-primary-foreground">
                                        Start here
                                    </span>
                                )}

                                <span
                                    className={[
                                        'mb-4 flex size-11 items-center justify-center rounded-xl',
                                        featured
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-accent text-accent-foreground',
                                    ].join(' ')}
                                >
                                    <Icon className="size-5" />
                                </span>

                                <h3 className="font-heading text-lg font-bold">{plan.name}</h3>

                                {plan.description && (
                                    <p className="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">
                                        {plan.description}
                                    </p>
                                )}

                                <p className="mt-5 flex items-baseline gap-1">
                                    <span className="font-heading text-4xl font-extrabold">
                                        ${perMonth.toFixed(perMonth % 1 === 0 ? 0 : 2)}
                                    </span>
                                    <span className="text-sm text-muted-foreground">/month</span>
                                </p>

                                <p className="mt-1 h-4 text-xs text-muted-foreground">
                                    {yearly && (
                                        <>
                                            ${plan.yearly.toFixed(2)} billed yearly
                                        </>
                                    )}
                                </p>

                                <Button
                                    className="mt-5 w-full"
                                    variant={featured ? 'default' : 'outline'}
                                    asChild
                                >
                                    <Link href={route('register')}>Start free</Link>
                                </Button>
                            </div>
                        </Reveal>
                    );
                })}
            </div>

            <p className="mt-8 flex items-center justify-center gap-2 text-sm text-muted-foreground">
                <Check className="size-4 text-primary" />
                Every service runs in sandbox until you decide to go live
            </p>
        </div>
    );
}
