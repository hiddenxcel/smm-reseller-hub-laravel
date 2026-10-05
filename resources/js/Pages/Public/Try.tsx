import Reveal from '@/components/landing/Reveal';
import { Section } from '@/components/landing/Section';
import Seo from '@/components/Seo';
import WhatsAppSimulator, { SimulatorConfig } from '@/components/simulator/WhatsAppSimulator';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PublicLayout from '@/Layouts/PublicLayout';
import { Link } from '@inertiajs/react';
import { ArrowRight, Bot, CheckCircle2, Headset, Rocket } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Props = {
    simulator: SimulatorConfig;
    demoNumber: string | null;
    assistantEnabled: boolean;
};

const TRY = [
    {
        icon: Bot,
        title: 'Order Bot',
        lines: [
            'Tap the menu, choose New order, pick a platform and a service.',
            'Send any link, confirm, and watch the wallet pay for it.',
            'Ask for more than the wallet holds — it offers a top-up.',
        ],
    },
    {
        icon: Headset,
        title: 'Support Bot',
        lines: [
            'Switch to Support Bot above the phone.',
            'Reply 6 for order status, 1 for a refill, 5 for a human.',
            'Any order number works — it is a practice chat.',
        ],
    },
];

/**
 * The product, working, with nothing to sign up for first.
 *
 * Most people who read a page about a WhatsApp bot want one thing before they
 * give an email address: to see it answer. This is the same screen a new
 * reseller meets in setup, running the same bot on a sample shop. The only
 * thing the visitor can change is the shop's name, because seeing your own
 * name in the greeting is what makes it feel like yours.
 */
export default function Try({ simulator, demoNumber, assistantEnabled }: Props) {
    const [name, setName] = useState('');
    const [applied, setApplied] = useState('');
    const [tried, setTried] = useState(false);

    // Applied on submit rather than on every keystroke: changing the name
    // restarts the chat, and a chat that restarts per letter is unusable.
    const apply = (event: FormEvent) => {
        event.preventDefault();
        setApplied(name.trim());
    };

    const config: SimulatorConfig = {
        ...simulator,
        business: applied || simulator.business,
    };

    return (
        <PublicLayout
            eyebrow="No signup needed"
            title="Try the real bot, right here"
            description="This is the exact bot your customers would message. Place an order, ask for a refill, break it. Nothing is charged, saved or sent anywhere."
            demoNumber={demoNumber}
            assistantEnabled={assistantEnabled}
        >
            <Seo
                title="Try the WhatsApp bot"
                description="Chat with a live demo of the Auto Resellers Hub WhatsApp bots — place an order, check a refill, talk to support — without creating an account."
            />

            <Section className="pt-0">
                <div className="mx-auto grid max-w-5xl gap-10 lg:grid-cols-[1fr_22rem] lg:items-start xl:gap-16">
                    <div className="order-2 lg:order-1">
                        <Reveal>
                            <form onSubmit={apply} className="card-surface rounded-2xl p-5">
                                <Label htmlFor="shop">Name your shop</Label>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    It shows up in the bot&rsquo;s greeting, the way yours will.
                                </p>
                                <div className="mt-3 flex gap-2">
                                    <Input
                                        id="shop"
                                        value={name}
                                        onChange={(event) => setName(event.target.value)}
                                        placeholder="e.g. Kuza Panel"
                                        maxLength={40}
                                        // 16px: below that iOS zooms the page on focus.
                                        className="text-base sm:text-sm"
                                    />
                                    <Button type="submit" variant="outline" disabled={! name.trim()}>
                                        Use name
                                    </Button>
                                </div>
                            </form>
                        </Reveal>

                        <div className="mt-6 grid gap-4 sm:grid-cols-2">
                            {TRY.map((item, index) => (
                                <Reveal key={item.title} delay={index * 80} card className="h-full">
                                    <div className="card-surface h-full rounded-2xl p-5">
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
                                </Reveal>
                            ))}
                        </div>

                        {/* Offered after they have played, not before: the first
                            thing on this page should be the chat, not a pitch. */}
                        <div
                            className={[
                                'mt-8 rounded-2xl border border-primary/25 bg-gradient-to-r from-primary/10 to-primary/5 p-5 transition-all duration-500 sm:p-6',
                                tried ? 'translate-y-0 opacity-100' : 'translate-y-2 opacity-60',
                            ].join(' ')}
                        >
                            <h2 className="font-heading text-lg font-bold">Want this on your own panel?</h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Connect your panel and the same bot sells your services at your
                                prices. Free to start, no card, live in about five minutes.
                            </p>

                            <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-muted-foreground">
                                {['Free to start', 'No card required', 'Sandbox until you go live'].map((item) => (
                                    <li key={item} className="flex items-center gap-1.5">
                                        <CheckCircle2 className="size-4 text-primary" />
                                        {item}
                                    </li>
                                ))}
                            </ul>

                            <Button size="lg" className="mt-4 w-full shadow-lg shadow-primary/25 sm:w-auto" asChild>
                                <Link href={route('register')}>
                                    <Rocket className="size-4" />
                                    Create my free account
                                    <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                        </div>
                    </div>

                    <div className="order-1 lg:sticky lg:top-24 lg:order-2">
                        {/* Remounted when the name changes: that is what restarts
                            the chat with the new greeting. */}
                        <WhatsAppSimulator key={config.business} config={config} onUsed={() => setTried(true)} />
                    </div>
                </div>
            </Section>
        </PublicLayout>
    );
}
