import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ArrowRight, Check, Copy, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import Markdown, { closeOpenMarkup } from './Markdown';
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
const WORD_MS = 16;
const REVEAL_CAP_MS = 900;

/** The assistant's face, so a column of replies reads as someone speaking. */
function Avatar() {
    return (
        <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
            <Sparkles className="size-3.5" />
        </span>
    );
}

/** Three dots, while the answer is being written. */
export function AssistantTyping({ locale }: { locale: Locale }) {
    return (
        <div className="flex items-start gap-2">
            <Avatar />
            <div className="flex items-center gap-1 rounded-2xl rounded-ss-md bg-muted px-4 py-3.5">
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

export default function AssistantMessage({ turn, locale }: { turn: AssistantTurn; locale: Locale }) {
    const mine = turn.role === 'user';

    return (
        <div
            className={cn(
                'flex animate-in items-start gap-2 fade-in slide-in-from-bottom-2 duration-300',
                mine ? 'justify-end' : 'justify-start',
            )}
        >
            {!mine && <Avatar />}

            <div className={cn('flex min-w-0 max-w-[88%] flex-col gap-2', mine && 'items-end')}>
                <div
                    className={cn(
                        'group relative rounded-2xl px-4 py-2.5 text-[0.9rem] leading-relaxed',
                        // One squared corner on the sender's side. It is the
                        // whole reason these read as a conversation rather
                        // than as a list of cards.
                        mine
                            ? 'rounded-ee-md bg-gradient-to-br from-primary to-primary/85 whitespace-pre-line text-primary-foreground shadow-sm'
                            : 'rounded-ss-md bg-muted text-foreground',
                    )}
                >
                    {mine ? turn.text : <Revealed text={turn.text} animate={turn.fresh === true} />}
                </div>

                {!mine && <CopyButton text={turn.text} locale={locale} />}

                {turn.ctas && turn.ctas.length > 0 && (
                    <div className="flex w-full flex-col gap-1.5">
                        {turn.ctas.map((cta, index) => (
                            <Cta key={cta.url + cta.label} cta={cta} primary={index === 0} />
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

/** Copy an answer — people paste these into a note or a chat with a colleague. */
function CopyButton({ text, locale }: { text: string; locale: Locale }) {
    const copy = t(locale);
    const [done, setDone] = useState(false);

    return (
        <button
            type="button"
            onClick={() => {
                void navigator.clipboard
                    ?.writeText(text.replace(/\[\[CTA:[^\]]*\]\]/g, '').trim())
                    .then(() => {
                        setDone(true);
                        window.setTimeout(() => setDone(false), 1600);
                    })
                    .catch(() => undefined);
            }}
            aria-label={copy.copy}
            className="-mt-1 flex items-center gap-1 self-start rounded-md px-1.5 py-0.5 text-[0.7rem] text-muted-foreground/70 transition-colors hover:bg-muted hover:text-foreground"
        >
            {done ? <Check className="size-3 text-emerald-500" /> : <Copy className="size-3" />}
            {done ? copy.copied : copy.copy}
        </button>
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
function Cta({ cta, primary }: { cta: NonNullable<AssistantTurn['ctas']>[number]; primary: boolean }) {
    return (
        <button
            type="button"
            onClick={() => router.visit(cta.url, { preserveState: true, preserveScroll: false })}
            className={cn(
                'group flex w-full items-center justify-between gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-all focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                primary
                    ? 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90'
                    : 'border border-border bg-background text-foreground hover:border-primary/40 hover:bg-muted',
            )}
        >
            {cta.label}
            <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" />
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

        // Cut on whitespace, so a word never appears half-written.
        const words = text.split(/(\s+)/);
        const step = Math.min(WORD_MS, REVEAL_CAP_MS / Math.max(1, words.length / 2));

        let index = 0;

        const timer = window.setInterval(() => {
            index += 2;

            setShown(words.slice(0, index).join('').length);

            if (index >= words.length) {
                window.clearInterval(timer);
            }
        }, step);

        return () => window.clearInterval(timer);
    }, [text, animate]);

    return (
        <>
            <div aria-hidden>
                <Markdown text={closeOpenMarkup(text.slice(0, shown))} />
            </div>
            <span className="sr-only">{text}</span>
        </>
    );
}
