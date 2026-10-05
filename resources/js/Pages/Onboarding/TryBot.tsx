import OnboardingLayout, { WizardStep } from '@/Layouts/OnboardingLayout';
import WhatsAppSimulator, { SimulatorConfig } from '@/components/simulator/WhatsAppSimulator';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Bot, Headset, Sparkles } from 'lucide-react';
import { useState } from 'react';

type Props = {
    step: string;
    steps: WizardStep[];
    completed: number;
    canSkip: boolean;
    simulator: SimulatorConfig;
};

const TRY = [
    {
        icon: Bot,
        title: 'Order Bot',
        lines: [
            'Tap the menu, choose New order, then pick a platform and a service.',
            'Send a link, confirm, and watch the wallet pay for it.',
            'Try asking for more than the wallet holds — it offers a top-up.',
        ],
    },
    {
        icon: Headset,
        title: 'Support Bot',
        lines: [
            'Switch to Support Bot above the phone.',
            'Reply 6 for order status, 1 for a refill, 5 to reach a human.',
            'Any order number works here — it is a rehearsal.',
        ],
    },
];

/**
 * The first thing a new reseller sees: the product working.
 *
 * Everything after this asks for something — a panel key, a WhatsApp number,
 * payment details — and people give those up far more readily once they have
 * seen what they are for. Nothing here needs setup: the screen runs the real
 * bot against a sample shop, so the only effort asked is a few taps.
 */
export default function TryBot({ step, steps, completed, canSkip, simulator }: Props) {
    const [tried, setTried] = useState(false);

    return (
        <OnboardingLayout step={step} steps={steps} completed={completed} canSkip={canSkip}>
            <Head title="Try your bot" />

            <div className="grid gap-10 lg:grid-cols-[1fr_22rem] lg:items-start xl:gap-14">
                <div className="order-2 lg:order-1">
                    <p className="mb-3 inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-1 text-xs font-semibold tracking-wider text-primary uppercase">
                        <Sparkles className="size-3.5" />
                        Start here
                    </p>

                    <h1 className="font-heading text-3xl font-extrabold text-balance sm:text-4xl">
                        See your bot work before you set anything up
                    </h1>

                    <p className="mt-3 max-w-xl text-muted-foreground">
                        This is the same bot your customers will talk to, running on a sample shop.
                        Place an order, ask for a refill, try everything. Nothing is charged, sent
                        to a panel or saved.
                    </p>

                    <div className="mt-8 grid gap-4 sm:grid-cols-2">
                        {TRY.map((item) => (
                            <div key={item.title} className="card-surface rounded-2xl p-5">
                                <div className="mb-3 flex items-center gap-2.5">
                                    <span className="flex size-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                        <item.icon className="size-4" />
                                    </span>
                                    <h2 className="font-heading font-bold">{item.title}</h2>
                                </div>

                                <ul className="space-y-2 text-sm text-muted-foreground">
                                    {item.lines.map((line) => (
                                        <li key={line} className="flex gap-2">
                                            <span aria-hidden className="mt-2 size-1 shrink-0 rounded-full bg-primary" />
                                            {line}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>

                    {/* Appears once they have played, not before: the first screen
                        is for looking, and a big button would pull them past it. */}
                    <div
                        className={[
                            'mt-8 rounded-2xl border border-primary/25 bg-gradient-to-r from-primary/10 to-primary/5 p-5 transition-all duration-500 sm:p-6',
                            tried ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-2 opacity-40',
                        ].join(' ')}
                    >
                        <h2 className="font-heading text-lg font-bold">
                            Like it? Now make it yours.
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Connect your panel and the bot sells your services at your prices, under
                            your shop&rsquo;s name. It takes about five minutes.
                        </p>

                        <Button size="lg" className="mt-4 w-full sm:w-auto" asChild>
                            <Link href={route('onboarding.step', 'panel')}>
                                Connect my panel
                                <ArrowRight className="size-4" />
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="order-1 lg:sticky lg:top-6 lg:order-2">
                    <WhatsAppSimulator config={simulator} onUsed={() => setTried(true)} />
                </div>
            </div>
        </OnboardingLayout>
    );
}
