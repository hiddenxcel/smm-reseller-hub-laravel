import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { t } from './strings';
import type { AssistantTurn, Locale } from './useAssistant';

/**
 * How fast a fresh answer appears, in milliseconds per word, and the longest
 * the whole reveal may take.
 *
 * This is not streaming — the answer arrived whole. It is the difference
 * between a reply that lands as a wall and one that reads as it is written,
 * and it costs nothing. The cap matters more than the rate: a long answer
 * must never become a long wait.
 */
const WORD_MS = 14;
const REVEAL_CAP_MS = 550;

/** Three dots, while the answer is being written. */
export function AssistantTyping({ locale }: { locale: Locale }) {
    return (
        <div className="flex justify-start">
            <div className="flex items-center gap-1 rounded-2xl rounded-bl-md bg-muted px-4 py-3">
                {[0, 1, 2].map((dot) => (
                    <span
                        key={dot}
                        className="size-1.5 animate-bounce rounded-full bg-muted-foreground/50"
                        style={{ animationDelay: `${dot * 150}ms` }}
                    />
                ))}
                <span className="sr-only">{t(locale).thinking}</span>
            </div>
        </div>
    );
}

export default function AssistantMessage({ turn }: { turn: AssistantTurn }) {
    const mine = turn.role === 'user';

    return (
        <div
            className={cn(
                'flex animate-in fade-in slide-in-from-bottom-2 duration-300',
                mine ? 'justify-end' : 'justify-start',
            )}
        >
            <div className={cn('flex max-w-[85%] flex-col gap-2', mine && 'items-end')}>
                <div
                    className={cn(
                        'rounded-2xl px-4 py-2.5 text-sm leading-relaxed whitespace-pre-line',
                        // One squared corner on the sender's side. It is the
                        // whole reason these read as a conversation rather
                        // than as a list of cards.
                        mine
                            ? 'rounded-br-md bg-primary text-primary-foreground'
                            : 'rounded-bl-md bg-muted text-foreground',
                    )}
                >
                    {mine ? turn.text : <Revealed text={turn.text} animate={turn.fresh === true} />}
                </div>

                {turn.cta && <Cta cta={turn.cta} />}
            </div>
        </div>
    );
}

/**
 * The button under an answer.
 *
 * A client-side visit rather than a link, and the panel is left open: somebody
 * who reaches /pricing from the chat usually has a second question, and losing
 * the conversation at that moment is losing it at the only moment it was
 * working. This is the thing an embedded iframe widget cannot do.
 */
function Cta({ cta }: { cta: NonNullable<AssistantTurn['cta']> }) {
    return (
        <button
            type="button"
            onClick={() => router.visit(cta.url, { preserveState: true, preserveScroll: false })}
            className="group flex w-full items-center justify-between gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary/90 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            {cta.label}
            <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
        </button>
    );
}

/**
 * An answer appearing a few words at a time.
 *
 * Skipped entirely for anyone who has asked for reduced motion, and for
 * restored history — in both cases the text is simply there. The full text is
 * always in the DOM for screen readers; only its visible extent changes.
 */
function Revealed({ text, animate }: { text: string; animate: boolean }) {
    const [shown, setShown] = useState(() => (animate ? 0 : text.length));

    useEffect(() => {
        if (!animate) {
            setShown(text.length);

            return;
        }

        const reduced =
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (reduced) {
            setShown(text.length);

            return;
        }

        const words = text.split(' ');
        const step = Math.min(WORD_MS, REVEAL_CAP_MS / Math.max(1, words.length));

        let index = 0;

        const timer = window.setInterval(() => {
            index += 1;

            setShown(words.slice(0, index).join(' ').length);

            if (index >= words.length) {
                window.clearInterval(timer);
            }
        }, step);

        return () => window.clearInterval(timer);
    }, [text, animate]);

    return (
        <>
            <span aria-hidden>{text.slice(0, shown)}</span>
            <span className="sr-only">{text}</span>
        </>
    );
}
