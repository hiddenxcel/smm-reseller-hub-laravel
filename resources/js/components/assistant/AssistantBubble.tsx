import { Sparkles, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { t } from './strings';
import type { Locale } from './useAssistant';

/**
 * How long the page is left alone before the assistant offers itself, and how
 * long the offer stays up.
 *
 * Three seconds is past the fold on most screens and past the first paragraph
 * on all of them. Eight is long enough to read and short enough that ignoring
 * it costs nothing.
 */
const PEEK_AFTER_MS = 3000;
const PEEK_FOR_MS = 8000;

/** Remembered per tab, so it offers once rather than on every page. */
const PEEKED_KEY = 'resellershub.assistant.peeked';

/**
 * The button that opens the chat.
 *
 * Sparkles rather than a speech bubble: a speech bubble promises a person, and
 * a visitor who opens this expecting one and finds a bot has been misled
 * before the first word. The pulse says something is waiting; the peek says
 * what for.
 */
export default function AssistantBubble({
    open,
    unread,
    locale,
    onOpen,
    peekEnabled = true,
}: {
    open: boolean;
    unread: boolean;
    locale: Locale;
    onOpen: () => void;
    /** Off inside the signed-in app, where nobody is being invited to sign up. */
    peekEnabled?: boolean;
}) {
    const copy = t(locale);

    const [peek, setPeek] = useState(false);

    useEffect(() => {
        if (open || !peekEnabled) {
            return;
        }

        let seen = true;

        try {
            seen = window.sessionStorage.getItem(PEEKED_KEY) === '1';
        } catch {
            // Private mode. Not offering is the safe failure.
        }

        if (seen) {
            return;
        }

        const show = window.setTimeout(() => {
            setPeek(true);

            try {
                window.sessionStorage.setItem(PEEKED_KEY, '1');
            } catch {
                // See above.
            }
        }, PEEK_AFTER_MS);

        const hide = window.setTimeout(() => setPeek(false), PEEK_AFTER_MS + PEEK_FOR_MS);

        return () => {
            window.clearTimeout(show);
            window.clearTimeout(hide);
        };
    }, [open, peekEnabled]);

    return (
        <div
            className={[
                'fixed right-4 bottom-4 z-50 flex items-end gap-2 transition-all duration-300 sm:right-6 sm:bottom-6',
                open ? 'pointer-events-none scale-90 opacity-0' : 'scale-100 opacity-100',
            ].join(' ')}
        >
            {peek && (
                <button
                    type="button"
                    onClick={() => {
                        setPeek(false);
                        onOpen();
                    }}
                    className="relative mb-2 max-w-[15rem] animate-in rounded-2xl rounded-br-md border border-border bg-card px-3.5 py-2.5 text-left text-sm shadow-lg fade-in slide-in-from-right-2 duration-500"
                >
                    <span className="block font-semibold">{copy.peekTitle}</span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {copy.peekBody}
                    </span>

                    {/* Dismisses without opening. A span rather than a button:
                        this sits inside one, and nesting them is invalid. */}
                    <span
                        role="presentation"
                        onClick={(event) => {
                            event.stopPropagation();
                            setPeek(false);
                        }}
                        className="absolute -top-2 -right-2 flex size-5 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-sm"
                    >
                        <X className="size-3" />
                    </span>
                </button>
            )}

            <button
                type="button"
                onClick={onOpen}
                aria-label={copy.open}
                className="group relative flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-primary to-primary/75 text-primary-foreground shadow-2xl shadow-primary/25 transition-transform hover:scale-105 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                {/* One quiet ring. Something is here and awake — not an advert. */}
                <span className="absolute inline-flex size-full animate-ping rounded-full bg-primary/25" />

                <Sparkles className="relative size-6" />

                {unread && (
                    <span className="absolute -top-0.5 -right-0.5 size-3.5 rounded-full border-2 border-background bg-destructive" />
                )}
            </button>
        </div>
    );
}
