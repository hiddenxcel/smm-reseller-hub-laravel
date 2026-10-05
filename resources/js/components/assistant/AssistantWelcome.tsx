import { router } from '@inertiajs/react';
import { ArrowRight, Bot, Globe, LifeBuoy, PlayCircle, Rocket, Smartphone, Wallet } from 'lucide-react';
import { t } from './strings';
import type { Locale } from './useAssistant';

/**
 * The questions people actually open this chat to ask.
 *
 * Each is worded exactly as a knowledge row in English and Kiswahili, so
 * tapping one is answered from the written answer rather than by the model:
 * instantly, at no cost, and in the words we chose. That is what makes the
 * first tap feel fast, which is the tap that decides whether there is a second
 * one. In every other language the same tap goes to the model, in that
 * language — the labels come from strings.ts either way.
 */
const QUICK = [
    { icon: Bot, key: 'orderBot' },
    { icon: LifeBuoy, key: 'supportBot' },
    { icon: Wallet, key: 'pricing' },
    { icon: Rocket, key: 'start' },
    { icon: Smartphone, key: 'ownNumber' },
    { icon: Globe, key: 'languages' },
] as const;

/**
 * What the assistant opens with, given where they are reading.
 *
 * Someone on the pricing page has already told us what they came for, and
 * greeting them with a blank "how can I help?" wastes the one thing we know
 * about them.
 */
function greeting(page: string, locale: Locale): string {
    const { openers } = t(locale);

    if (page.startsWith('/pricing')) {
        return openers.pricing;
    }

    if (page.startsWith('/features') || page.startsWith('/what-we-do')) {
        return openers.features;
    }

    if (page.startsWith('/api-docs')) {
        return openers.apiDocs;
    }

    if (page.startsWith('/blog')) {
        return openers.blog;
    }

    if (page.startsWith('/contact')) {
        return openers.contact;
    }

    if (page.startsWith('/try')) {
        return openers.try;
    }

    return openers.home;
}

export default function AssistantWelcome({
    page,
    locale,
    onPick,
}: {
    page: string;
    locale: Locale;
    onPick: (question: string) => void;
}) {
    const copy = t(locale);

    // Pointless on the page that is already the practice chat.
    const showTry = !page.startsWith('/try');

    return (
        <div className="flex flex-col gap-5 px-4 py-5">
            <div className="animate-in fade-in slide-in-from-bottom-2 duration-500">
                <p className="text-xl font-extrabold tracking-tight">{copy.greeting}</p>
                <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                    {greeting(page, locale)}
                </p>
            </div>

            {showTry && (
                <button
                    type="button"
                    onClick={() => router.visit('/try', { preserveState: true })}
                    style={{ animationDelay: '60ms' }}
                    className="group relative flex animate-in items-center gap-3 overflow-hidden rounded-2xl bg-gradient-to-br from-primary to-primary/75 p-4 text-start text-primary-foreground shadow-lg shadow-primary/25 fill-mode-backwards fade-in slide-in-from-bottom-2 duration-500 hover:brightness-105 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -end-6 -top-8 size-28 rounded-full bg-white/15 blur-2xl"
                    />
                    <span className="relative flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/20">
                        <PlayCircle className="size-6" />
                    </span>
                    <span className="relative min-w-0 flex-1">
                        <span className="block text-sm font-bold">{copy.tryTitle}</span>
                        <span className="mt-0.5 block text-xs text-primary-foreground/85">{copy.tryBody}</span>
                    </span>
                    <ArrowRight className="relative size-4 shrink-0 transition-transform group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" />
                </button>
            )}

            <div>
                <p className="mb-2 text-[0.7rem] font-semibold tracking-wider text-muted-foreground uppercase">
                    {copy.popular}
                </p>

                <div className="grid grid-cols-2 gap-2">
                    {QUICK.map(({ icon: Icon, key }, index) => {
                        const item = copy.quick[key];

                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => onPick(item.ask)}
                                // Staggered so the grid assembles rather than
                                // appearing — the difference between a panel
                                // that opens and one that simply exists.
                                style={{ animationDelay: `${120 + index * 45}ms` }}
                                className="group flex animate-in flex-col items-start gap-2 rounded-xl border border-border bg-background/60 p-3 text-start text-[0.8rem] leading-snug font-medium fill-mode-backwards transition-all fade-in slide-in-from-bottom-2 duration-500 hover:-translate-y-0.5 hover:border-primary/40 hover:bg-muted hover:shadow-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <span className="flex size-7 items-center justify-center rounded-lg bg-primary/10 text-primary transition-colors group-hover:bg-primary group-hover:text-primary-foreground">
                                    <Icon className="size-3.5" />
                                </span>
                                {item.label}
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
