import { Check, KeyRound, Link2, Wand2 } from 'lucide-react';

/**
 * Answers the first question a reseller asks: will this work with the panel
 * I already have?
 *
 * Named in our own type rather than with their logos. Perfect Panel, Apex and
 * the rest are other companies' trademarks, and their marks beside our
 * branding would read as an endorsement none of them have given. Saying a
 * panel is compatible is a fact we can state; borrowing its identity is not.
 *
 * The claim itself is what SmmProviderClient actually does — form-encoded
 * POST against the standard v2 API, with both auth styles tried in turn.
 */

const KNOWN = [
    'Perfect Panel',
    'Apex Panel',
    'Glycon',
    'SMM Panel Script',
    'PayPanel',
    'Rental Panel',
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
        <div className="mx-auto max-w-4xl">
            <div className="grid gap-8 sm:grid-cols-3">
                {STEPS.map((step) => (
                    <div key={step.title}>
                        <span className="mb-3 flex size-10 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                            <step.icon className="size-5" />
                        </span>
                        <h3 className="font-heading mb-1.5 font-bold">{step.title}</h3>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            {step.body}
                        </p>
                    </div>
                ))}
            </div>

            <div className="mt-10 rounded-2xl border border-border bg-card p-6">
                <p className="text-sm font-semibold">Panels we already speak to</p>

                <ul className="mt-4 flex flex-wrap gap-2">
                    {KNOWN.map((panel) => (
                        <li
                            key={panel}
                            className="flex items-center gap-1.5 rounded-lg bg-muted px-3 py-1.5 text-sm"
                        >
                            <Check className="size-3.5 shrink-0 text-primary" aria-hidden />
                            {panel}
                        </li>
                    ))}
                </ul>

                <p className="mt-4 text-sm text-muted-foreground">
                    Not on the list? If your panel speaks the standard SMM API v2 —
                    and nearly all of them do — it will connect. Names are given for
                    identification only; we are not affiliated with any of them.
                </p>
            </div>
        </div>
    );
}
