import AppLogo from '@/components/AppLogo';
import {
    Check,
    CreditCard,
    KeyRound,
    Link2,
    MessageCircle,
    Server,
    Wand2,
} from 'lucide-react';
import Reveal from './Reveal';

/**
 * Answers the first question a reseller asks: will this work with the panel I
 * already have?
 *
 * Drawn as a flow rather than listed, because the shape is the point — the
 * panel they already run on one side, their customers on the other, and this
 * sitting between the two. A reseller who thinks they are being sold a
 * replacement panel is the one who does not sign up.
 *
 * Names are set in our own type rather than shown as logos. Perfect Panel and
 * the rest are other companies' trademarks, and their marks beside our
 * branding would read as an endorsement none of them have given. Saying a
 * panel is compatible is a fact; borrowing its identity is not.
 *
 * The compatibility claim is what SmmProviderClient does — form-encoded POST
 * against the standard v2 API, both auth styles tried in turn.
 */

const PANELS = [
    'Perfect Panel',
    'Apex Panel',
    'Glycon',
    'SMM Panel Script',
    'PayPanel',
    'Rental Panel',
];

const OUTPUTS = [
    { icon: MessageCircle, label: 'WhatsApp', note: 'Orders and support' },
    { icon: CreditCard, label: 'Wallets', note: 'Mobile money and crypto' },
    { icon: Check, label: 'Refills', note: 'Against your rules' },
];

const STEPS = [
    {
        icon: Link2,
        title: 'Paste the address',
        body: 'Just the domain — we work out whether it wants /api/v2 on the end.',
    },
    {
        icon: KeyRound,
        title: 'Paste the admin key',
        body: 'Encrypted before we store it, and only ever used to reach your panel.',
    },
    {
        icon: Wand2,
        title: 'We detect the rest',
        body: 'Which API version it speaks, and whether the key goes in the body or a header.',
    },
];

export default function PanelCompatibility() {
    return (
        <div>
            {/* ---- the flow ---- */}
            <div className="grid items-center gap-8 lg:grid-cols-[1fr_auto_1fr] lg:gap-4">
                {/* panels in */}
                <div className="space-y-2.5">
                    <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase lg:text-right">
                        Panels we speak to
                    </p>

                    {PANELS.map((panel, index) => (
                        <Reveal key={panel} delay={index * 60} index={index} card>
                            <div className="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-2.5 lg:ml-auto lg:max-w-[16rem]">
                                <Server className="size-4 shrink-0 text-muted-foreground" />
                                <span className="truncate text-sm font-medium">{panel}</span>
                                <Check className="ml-auto size-4 shrink-0 text-primary" />
                            </div>
                        </Reveal>
                    ))}
                </div>

                {/* the hub */}
                <Reveal delay={200}>
                    <div className="relative flex flex-col items-center py-4 lg:px-10">
                        {/* Lines into the hub and out the other side. Only on
                            desktop: stacked, the columns are above and below
                            rather than left and right, and the lines would
                            point at nothing. */}
                        <span
                            aria-hidden
                            className="absolute top-[3.25rem] -left-6 hidden h-px w-16 bg-gradient-to-r from-transparent to-primary/50 lg:block"
                        />
                        <span
                            aria-hidden
                            className="absolute top-[3.25rem] -right-6 hidden h-px w-16 bg-gradient-to-l from-transparent to-primary/50 lg:block"
                        />
                        {/* A ring that keeps breathing, so the centre reads as
                            the live piece rather than another card. Pure CSS,
                            and it stops under prefers-reduced-motion. */}
                        <span
                            aria-hidden
                            className="absolute top-4 size-20 animate-ping rounded-3xl bg-primary/15 motion-reduce:animate-none lg:top-auto"
                        />

                        <span className="relative flex size-20 items-center justify-center rounded-3xl bg-primary shadow-lg">
                            <AppLogo className="size-11 rounded-2xl" />
                        </span>

                        <p className="font-heading mt-3 text-center text-sm font-bold">
                            Resellers Hub
                        </p>
                        <p className="text-center text-xs text-muted-foreground">
                            Sits on top
                        </p>
                    </div>
                </Reveal>

                {/* what comes out */}
                <div className="space-y-2.5">
                    <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                        What your customers get
                    </p>

                    {OUTPUTS.map((out, index) => (
                        <Reveal key={out.label} delay={260 + index * 80}>
                            <div className="flex items-center gap-3 rounded-xl border border-primary/25 bg-primary/5 px-4 py-2.5 lg:max-w-[16rem]">
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                    <out.icon className="size-4 text-primary" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-semibold">
                                        {out.label}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {out.note}
                                    </span>
                                </span>
                            </div>
                        </Reveal>
                    ))}
                </div>
            </div>

            <Reveal>
                <p className="mx-auto mt-10 max-w-2xl text-center text-sm text-pretty text-muted-foreground">
                    Not on the list? If your panel speaks the standard SMM API v2 —
                    and nearly all of them do — it will connect. Names are given for
                    identification only; we are not affiliated with any of them.
                </p>
            </Reveal>

            {/* ---- how connecting works ---- */}
            <div className="mt-14 grid gap-8 border-t border-border pt-10 sm:grid-cols-3">
                {STEPS.map((step, index) => (
                    <Reveal key={step.title} delay={index * 100} index={index}>
                        <p className="mb-3 flex items-center gap-2 text-xs font-semibold text-primary">
                            <span className="flex size-5 items-center justify-center rounded-full bg-primary/10">
                                {index + 1}
                            </span>
                            Step {index + 1}
                        </p>

                        <span className="mb-3 flex size-10 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                            <step.icon className="size-5" />
                        </span>

                        <h3 className="font-heading mb-1.5 font-bold">{step.title}</h3>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            {step.body}
                        </p>
                    </Reveal>
                ))}
            </div>
        </div>
    );
}
