import { ArrowRight, Ban, CheckCircle2, Coins, Wallet } from 'lucide-react';
import { Fragment } from 'react';
import Reveal from './Reveal';

/**
 * Where the money goes, drawn as the route it takes.
 *
 * The distinction being made is the one resellers care about most and the one
 * a wall of prose loses: the funds move from their customer to their own
 * gateway account, and we are never in the middle. Three numbered steps with
 * an arrow between them say that faster than a paragraph can.
 *
 * The last panel states the negative outright, because "we never hold your
 * money" is the claim, and a claim left implied is a claim not made.
 */

const STEPS = [
    {
        icon: Coins,
        step: 'Your customer tops up',
        body: 'Mobile money or crypto, through the gateway account you connected — your keys, your merchant ID.',
    },
    {
        icon: CheckCircle2,
        step: 'The gateway confirms it',
        body: 'Not you. The webhook lands, the payment is verified, and the wallet balance moves on its own.',
    },
    {
        icon: Wallet,
        step: 'The order places itself',
        body: 'Balance covers it, so the order goes to your panel instantly. Short? They top up and it places itself.',
    },
];

export default function MoneyFlow() {
    return (
        <div>
            <div className="grid gap-4 lg:grid-cols-[1fr_auto_1fr_auto_1fr] lg:items-stretch lg:gap-2">
                {STEPS.map((item, index) => (
                    <Fragment key={item.step}>
                        <Reveal delay={index * 120} index={index} card className="h-full">
                            <div className="flex h-full flex-col rounded-2xl border border-border bg-card p-5">
                                <div className="mb-3 flex items-center gap-3">
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                        <item.icon className="size-5" />
                                    </span>
                                    <span className="font-heading text-2xl font-extrabold text-primary/25">
                                        0{index + 1}
                                    </span>
                                </div>

                                <h3 className="font-heading mb-1.5 font-bold">{item.step}</h3>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    {item.body}
                                </p>
                            </div>
                        </Reveal>

                        {index < STEPS.length - 1 && (
                            <div
                                aria-hidden
                                className="hidden items-center justify-center lg:flex"
                            >
                                <ArrowRight className="size-5 text-primary/40" />
                            </div>
                        )}
                    </Fragment>
                ))}
            </div>

            {/* The claim the whole section exists to make. */}
            <Reveal delay={200}>
                <div className="mt-4 flex items-start gap-3 rounded-2xl border border-primary/25 bg-primary/5 p-5">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <Ban className="size-5" />
                    </span>

                    <div>
                        <h3 className="font-heading font-bold">
                            The money never passes through us
                        </h3>
                        <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                            We do not hold funds, take a cut of an order, or sit between
                            you and your customer. You pay us for the bot; everything
                            your customers spend lands in your own accounts.
                        </p>
                    </div>
                </div>
            </Reveal>
        </div>
    );
}
