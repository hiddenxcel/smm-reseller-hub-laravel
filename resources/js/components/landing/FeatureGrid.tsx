import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import {
    BarChart3,
    Bot,
    Check,
    Gift,
    Languages,
    Layers,
    MessageSquare,
    Megaphone,
    Phone,
    ShieldCheck,
    Wallet,
} from 'lucide-react';
import Reveal from './Reveal';

/**
 * Only things that exist. A feature grid that promises what is not built is
 * how a first customer arrives disappointed.
 *
 * The rented number leads because it removes the one blocker a reseller
 * cannot solve on their own: the Cloud API needs a Meta Business account, and
 * getting one takes days a shop does not want to wait. Everything else is a
 * capability; this is the difference between starting today and not.
 */

const FEATURES = [
    {
        icon: Layers,
        title: 'Multiple panels',
        body: 'Connect more than one SMM panel and sell from all of them through the same bot.',
    },
    {
        icon: Bot,
        title: 'Orders without you',
        body: 'Platform, service, quantity, link, confirm — then straight onto your panel.',
    },
    {
        icon: ShieldCheck,
        title: 'Official Cloud API',
        body: "Meta's own API, so your number is not at risk the way QR-scanning tools are.",
    },
    {
        icon: Wallet,
        title: 'Customer wallets',
        body: 'Each customer holds a balance. Orders debit it instantly — no chasing payments.',
    },
    {
        icon: MessageSquare,
        title: 'Refills and status',
        body: 'Your guarantee rules decide who gets a refill, and the panel answers status questions.',
    },
    {
        icon: Languages,
        title: 'Order bot in five languages',
        body: 'English, French, Kiswahili, Turkish and Hindi — each customer in their own.',
    },
    {
        icon: Megaphone,
        title: 'Broadcast',
        body: "Message customers who wrote to you recently, within Meta's 24-hour window.",
    },
    {
        icon: Gift,
        title: 'Referrals',
        body: 'Reward customers who bring you more customers, as a share of their first deposit.',
    },
    {
        icon: BarChart3,
        title: 'Orders and revenue',
        body: 'See what sold, what it earned, and which customers keep coming back.',
    },
];

const RENTAL_POINTS = [
    'No Meta Business account needed',
    'Live the day you rent it',
    'Official Cloud API, same as your own',
    'Swap to your own number later',
];

export default function FeatureGrid() {
    return (
        <div>
            {/* ---- the one that unblocks a start ---- */}
            <Reveal>
                <div className="mb-6 grid items-center gap-8 rounded-3xl border border-primary/20 bg-gradient-to-br from-primary/10 via-primary/5 to-transparent p-5 sm:p-10 lg:grid-cols-2">
                    <div>
                        <p className="mb-3 flex w-fit items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold tracking-wide text-primary uppercase">
                            <Phone className="size-3.5" />
                            Rent a number
                        </p>

                        <h3 className="font-heading text-2xl font-extrabold text-balance sm:text-3xl">
                            No Meta account? Start anyway.
                        </h3>

                        <p className="mt-4 text-pretty text-muted-foreground">
                            The Cloud API needs a Meta Business account, and getting one
                            takes days. Rent a number from us instead and your bot is
                            answering today — on the same official API, not a workaround.
                        </p>

                        <ul className="mt-6 grid gap-2.5 sm:grid-cols-2">
                            {RENTAL_POINTS.map((point) => (
                                <li key={point} className="flex items-start gap-2 text-sm">
                                    <Check className="mt-0.5 size-4 shrink-0 text-primary" />
                                    {point}
                                </li>
                            ))}
                        </ul>

                        <Button className="mt-7" asChild>
                            <Link href={route('register')}>Start free</Link>
                        </Button>
                    </div>

                    {/* A number being handed over, drawn rather than described. */}
                    <div className="relative">
                        <div className="soft-lg mx-auto w-full max-w-sm rounded-2xl border border-border bg-card p-5">
                            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Your bot number
                            </p>

                            <p className="font-data mt-2 text-2xl font-bold">
                                +255 700 000 000
                            </p>

                            <span className="mt-3 flex w-fit items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
                                <span className="size-1.5 rounded-full bg-primary" />
                                Live · Cloud API
                            </span>

                            <div className="mt-5 space-y-2 border-t border-border pt-4">
                                {[
                                    'Verified by Meta',
                                    'Order bot connected',
                                    'Ready for customers',
                                ].map((line) => (
                                    <p
                                        key={line}
                                        className="flex items-center gap-2 text-sm text-muted-foreground"
                                    >
                                        <Check className="size-4 shrink-0 text-primary" />
                                        {line}
                                    </p>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </Reveal>

            {/* ---- everything else ---- */}
            <div className="grid gap-4 sm:grid-cols-2 sm:gap-5 lg:grid-cols-3">
                {FEATURES.map((feature, index) => (
                    <Reveal key={feature.title} delay={index * 60} index={index} card className="h-full">
                        <div className="card-surface group flex h-full flex-col rounded-2xl p-5 sm:p-6">
                            <span className="mb-4 flex size-11 items-center justify-center rounded-xl bg-accent ring-1 ring-primary/10 text-accent-foreground transition-colors duration-300 group-hover:bg-primary group-hover:text-primary-foreground">
                                <feature.icon className="size-5" />
                            </span>

                            <h3 className="font-heading mb-1.5 font-bold">{feature.title}</h3>

                            <p className="text-sm leading-relaxed text-muted-foreground">
                                {feature.body}
                            </p>
                        </div>
                    </Reveal>
                ))}
            </div>
        </div>
    );
}
