import { ArrowRight, Bot, LifeBuoy, Rocket, Smartphone, Wallet } from 'lucide-react';
import { t } from './strings';
import type { Locale } from './useAssistant';

/**
 * The five things people actually open this chat to ask.
 *
 * Each is worded exactly as a knowledge row — in both languages, since a
 * Kiswahili speaker tapping a button should not have to ask in English to be
 * understood. Tapping one is answered from the written answer rather than by
 * the model: instantly, at no cost, and in the words we chose. That is what
 * makes the first tap feel fast, which is the tap that decides whether there
 * is a second one.
 */
const ACTIONS = [
    {
        icon: Bot,
        key: 'orderBot',
        ask: { en: 'What is Order Bot?', sw: 'Order Bot ni nini?' },
    },
    {
        icon: LifeBuoy,
        key: 'supportBot',
        ask: { en: 'What is Support Bot?', sw: 'Support Bot ni nini?' },
    },
    {
        icon: Wallet,
        key: 'pricing',
        ask: { en: 'How much does it cost?', sw: 'Bei ni kiasi gani?' },
    },
    {
        icon: Rocket,
        key: 'start',
        ask: { en: 'How do I get started?', sw: 'Ninawezaje kuanza?' },
    },
    {
        icon: Smartphone,
        key: 'whatsappSetup',
        ask: {
            en: 'Can I use my own WhatsApp number?',
            sw: 'Naweza kutumia namba yangu ya WhatsApp?',
        },
    },
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

    return (
        <div className="flex flex-col gap-5 px-4 py-5">
            <div className="animate-in fade-in slide-in-from-bottom-2 duration-500">
                <p className="text-lg font-bold">{copy.greeting}</p>
                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                    {greeting(page, locale)}
                </p>
            </div>

            <div className="flex flex-col gap-2">
                {ACTIONS.map(({ icon: Icon, key, ask }, index) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => onPick(ask[locale] ?? ask.en)}
                        // Staggered so the list assembles rather than
                        // appearing — the difference between a panel that
                        // opens and one that simply exists.
                        style={{ animationDelay: `${80 + index * 45}ms` }}
                        className="group flex animate-in items-center gap-3 rounded-xl border border-border bg-background/60 px-3.5 py-3 text-left text-sm font-medium fill-mode-backwards transition-colors fade-in slide-in-from-bottom-2 duration-500 hover:border-primary/40 hover:bg-muted focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Icon className="size-4" />
                        </span>

                        <span className="flex-1">{copy.actions[key]}</span>

                        <ArrowRight className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                    </button>
                ))}
            </div>
        </div>
    );
}
