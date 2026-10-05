import AppLogo from '@/components/AppLogo';
import { Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { PropsWithChildren, ReactNode } from 'react';

/**
 * The shell every signed-out page sits in.
 *
 * Two columns on desktop: the form on the left, a brand panel on the right
 * carrying the same promises as the landing hero — signing up is the moment
 * that pitch has to hold. Below `lg` the brand panel drops entirely rather
 * than stacking; on a phone it would only push the form below the fold.
 */

const POINTS = [
    'Official Meta Cloud API — your number stays safe',
    'Crypto and mobile money, paid straight to you',
    'Sandbox first — test before you ever go live',
];

export default function Guest({
    title,
    description,
    children,
}: PropsWithChildren<{ title?: string; description?: ReactNode }>) {
    return (
        <div className="flex min-h-screen flex-col lg:flex-row">
            {/* ---- form column ---- */}
            <div className="flex flex-1 flex-col px-4 py-8 sm:px-8">
                <Link
                    href="/"
                    className="font-heading flex items-center gap-2 self-start text-lg font-extrabold"
                >
                    <AppLogo />
                    Auto Resellers Hub
                </Link>

                <div className="flex flex-1 items-center justify-center py-10">
                    <div className="w-full max-w-sm">
                        {title && (
                            <div className="mb-7">
                                <h1 className="font-heading text-2xl font-extrabold tracking-tight text-balance sm:text-3xl">
                                    {title}
                                </h1>
                                {description && (
                                    <p className="mt-2 text-sm text-pretty text-muted-foreground">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}

                        {children}
                    </div>
                </div>

                <p className="text-center text-xs text-muted-foreground lg:text-left">
                    © {new Date().getFullYear()} Auto Resellers Hub
                </p>
            </div>

            {/* ---- brand panel ---- */}
            <div className="relative hidden overflow-hidden bg-primary text-primary-foreground lg:flex lg:w-[46%] lg:max-w-xl lg:flex-col lg:justify-center lg:px-14">
                {/* Two soft washes so the flat green reads as lit, not printed. */}
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-24 -right-16 size-96 rounded-full bg-white/10 blur-3xl"
                />
                <div
                    aria-hidden
                    className="pointer-events-none absolute -bottom-32 -left-20 size-96 rounded-full bg-black/10 blur-3xl"
                />

                <div className="relative">
                    <h2 className="font-heading text-3xl leading-[1.15] font-extrabold text-balance xl:text-4xl">
                        Put your SMM panel on WhatsApp
                    </h2>

                    <p className="mt-5 max-w-md text-pretty text-primary-foreground/80">
                        Your customers order, pay and get support automatically —
                        around the clock, under your own brand.
                    </p>

                    <ul className="mt-9 space-y-4">
                        {POINTS.map((point) => (
                            <li key={point} className="flex items-start gap-3">
                                <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-primary-foreground/70" />
                                <span className="text-sm text-primary-foreground/90">
                                    {point}
                                </span>
                            </li>
                        ))}
                    </ul>

                    <dl className="mt-12 flex gap-10 border-t border-white/15 pt-8">
                        {[
                            // True on day one — see the note on TRUST in Landing.tsx.
                            { value: '24/7', label: 'Never closes' },
                            { value: '~5 min', label: 'To go live' },
                            { value: '$0', label: 'To start' },
                        ].map((stat) => (
                            <div key={stat.label}>
                                <dt className="font-heading text-2xl font-extrabold">
                                    {stat.value}
                                </dt>
                                <dd className="mt-0.5 text-xs text-primary-foreground/70">
                                    {stat.label}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
            </div>
        </div>
    );
}
