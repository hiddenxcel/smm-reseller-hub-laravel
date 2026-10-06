import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { ArrowUp, MessageCircle, RotateCcw, Sparkles, UserRound, X } from 'lucide-react';
import { KeyboardEvent, useEffect, useRef, useState } from 'react';
import AssistantBubble from './AssistantBubble';
import AssistantLeadForm from './AssistantLeadForm';
import AssistantMessage, { AssistantTyping } from './AssistantMessage';
import AssistantWelcome from './AssistantWelcome';
import LanguageMenu from './LanguageMenu';
import { isRtl, t } from './strings';
import { useAssistant } from './useAssistant';
import VoiceButton from './VoiceButton';

/**
 * The Auto Resellers Hub assistant.
 *
 * A visitor with a question about the bots has, until now, had two options:
 * leave the site for WhatsApp, or find the answer themselves. Most did
 * neither. This answers in place — in whatever language they write, from
 * written answers and the live price list — and hands over to a person when it
 * cannot.
 *
 * It replaces the floating WhatsApp button rather than joining it. Two things
 * hovering over the same corner is noise, and WhatsApp is better placed here
 * anyway — offered after the assistant has failed, rather than before the
 * visitor has asked.
 *
 * Server-rendered pages mount this, so nothing here may touch the browser
 * during render; see `mounted`.
 */
export default function AssistantWidget({
    demoNumber,
    inApp = false,
}: {
    demoNumber?: string | null;
    /**
     * Mounted inside the signed-in app rather than on the public site. Someone
     * who already has an account is not being sold to, so the sales peek stays
     * away; the chat itself is the same.
     */
    inApp?: boolean;
}) {
    const { url } = usePage();
    const page = url.split('?')[0] || '/';

    const [mounted, setMounted] = useState(false);
    const [open, setOpen] = useState(false);
    const [leaving, setLeaving] = useState(false);
    const [unread, setUnread] = useState(false);
    const [draft, setDraft] = useState('');

    const {
        turns,
        suggestions,
        pending,
        escalate,
        failed,
        rateLimited,
        locale,
        choice,
        setChoice,
        ask,
        reset,
        token,
        started,
    } = useAssistant(page);

    // Everything the widget says in its own voice follows the conversation's
    // language, so the answers and the furniture around them never disagree.
    const copy = t(locale);
    const rtl = isRtl(locale);

    const bottom = useRef<HTMLDivElement>(null);
    const scroller = useRef<HTMLDivElement>(null);
    const content = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLTextAreaElement>(null);

    // Whether the list should follow new content down. True until the visitor
    // scrolls up to re-read something, and true again when they send.
    const stick = useRef(true);

    // Read inside the observer below, which outlives any one render.
    const startedRef = useRef(false);
    startedRef.current = started;
    const answered = useRef(0);

    // The panel must not exist in the server-rendered HTML at all: it reads
    // sessionStorage and measures the viewport, neither of which exists in
    // Node, and a mismatch here would be visible on first paint.
    useEffect(() => setMounted(true), []);

    // Follows the conversation down. Auto rather than smooth while a reply is
    // arriving, so a long answer does not scroll for the length of its own
    // reveal.
    useEffect(() => {
        // Scrolls the list itself, never scrollIntoView: that one scrolls every
        // ancestor too, and on a page behind the panel it moves the page.
        const box = scroller.current;

        if (box && stick.current && started) {
            box.scrollTo({ top: box.scrollHeight, behavior: pending ? 'auto' : 'smooth' });
        }
    }, [turns, pending, suggestions, started]);

    // An answer is revealed a few words at a time, so its height keeps growing
    // after the turn that added it. Scrolling only on a new turn left the last
    // line cut off under the composer; watching the height itself keeps the
    // end in view for as long as the visitor has not scrolled away from it.
    useEffect(() => {
        const list = content.current;
        const box = scroller.current;

        if (!list || !box || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(() => {
            // Only once there is a conversation. The welcome screen reads from
            // the top; following its height down hid the greeting whenever the
            // language changed and the cards re-wrapped.
            if (stick.current && startedRef.current) {
                box.scrollTop = box.scrollHeight;
            }
        });

        observer.observe(list);

        return () => observer.disconnect();
    }, [mounted, open, started, leaving]);

    // Grows with what is typed, up to a few lines.
    useEffect(() => {
        const box = input.current;

        if (box) {
            box.style.height = 'auto';
            box.style.height = `${Math.min(box.scrollHeight, 112)}px`;
        }
    }, [draft, open]);

    // A dot on the bubble, but only for an answer that arrived while the panel
    // was shut. Marking every answer unread would make the dot mean nothing.
    useEffect(() => {
        const replies = turns.filter((turn) => turn.role === 'assistant').length;

        if (replies > answered.current && !open) {
            setUnread(true);
        }

        answered.current = replies;
    }, [turns, open]);

    if (!mounted) {
        return null;
    }

    function show() {
        setOpen(true);
        setUnread(false);
        setLeaving(false);

        // Only once there is something to reply to. Opening the keyboard on a
        // phone before the welcome screen has been read hides it.
        if (started) {
            window.setTimeout(() => input.current?.focus(), 150);
        }
    }

    function hide() {
        setOpen(false);
        setLeaving(false);
    }

    function send(text: string) {
        stick.current = true;
        setDraft('');
        setLeaving(false);
        void ask(text);
    }

    function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            send(draft);
        }
    }

    const whatsapp = demoNumber
        ? `https://wa.me/${demoNumber.replace(/\D/g, '')}?text=${encodeURIComponent(
              lastQuestion(turns) ?? 'Hi, I have a question about Auto Resellers Hub',
          )}`
        : null;

    return (
        <>
            <AssistantBubble
                open={open}
                unread={unread}
                locale={locale}
                onOpen={show}
                peekEnabled={!inApp}
            />

            {/* Only on a phone, where the panel is the whole screen. On a
                desktop the page behind stays readable and usable on purpose. */}
            {open && (
                <div
                    onClick={hide}
                    className="fixed inset-0 z-40 animate-in bg-background/60 backdrop-blur-sm fade-in duration-200 sm:hidden"
                />
            )}

            <div
                role="dialog"
                aria-label="Auto Resellers Hub assistant"
                dir={rtl ? 'rtl' : 'ltr'}
                className={cn(
                    'fixed z-50 flex flex-col overflow-hidden border-border bg-card shadow-2xl ring-1 ring-black/5 transition-all duration-200',
                    // Phone: a sheet filling most of the screen, full width. A
                    // 400px panel on a 390px screen is not a panel. It stops
                    // short of the top so the page behind stays visible —
                    // covering everything reads as having navigated away.
                    'inset-x-0 bottom-0 top-14 rounded-t-3xl border-t',
                    // Desktop: a corner panel with a fixed height, not one that
                    // grows with the conversation. An auto-height flex column
                    // has no height for its scroller to fill, so as the chat
                    // got longer the header, the answers and the composer all
                    // shrank together — the header cropped to a sliver and the
                    // text squeezed. A definite height gives the message list
                    // something to scroll inside, and nothing else moves.
                    'sm:inset-auto sm:right-6 sm:bottom-6 sm:top-auto sm:h-[min(38rem,calc(100dvh-6rem))] sm:w-[26.5rem] sm:rounded-3xl sm:border',
                    open
                        ? 'translate-y-0 opacity-100 sm:scale-100'
                        : 'pointer-events-none translate-y-4 opacity-0 sm:origin-bottom-right sm:translate-y-0 sm:scale-95',
                )}
            >
                {/* ---- header: a quiet gradient, not a banner ---- */}
                {/* No overflow-hidden on the header itself: the language menu hangs
                    below it, and a clipped header cut the menu to a sliver, so
                    tapping "AUTO" appeared to do nothing. The glow is clipped
                    in a layer of its own instead. z-30 so the menu sits above
                    the messages, not under them. */}
                <header className="relative z-30 flex shrink-0 items-center gap-3 bg-gradient-to-br from-primary to-primary/70 px-4 py-3.5 text-primary-foreground">
                    <span aria-hidden className="pointer-events-none absolute inset-0 overflow-hidden">
                        <span className="absolute -end-10 -top-12 size-36 rounded-full bg-white/15 blur-2xl" />
                        <span className="absolute -bottom-14 start-6 size-28 rounded-full bg-black/10 blur-2xl" />
                    </span>

                    <span className="relative flex size-10 shrink-0 items-center justify-center rounded-2xl bg-white/20 ring-1 ring-white/25 backdrop-blur">
                        <Sparkles className="size-5" />
                        <span className="absolute -end-0.5 -bottom-0.5 flex size-3">
                            <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-300/70" />
                            <span className="relative inline-flex size-3 rounded-full border-2 border-primary bg-emerald-300" />
                        </span>
                    </span>

                    <span className="relative min-w-0 flex-1">
                        <span className="block truncate text-sm leading-tight font-bold">{copy.title}</span>
                        <span className="mt-0.5 block truncate text-xs text-primary-foreground/80">
                            {copy.status}
                        </span>
                    </span>

                    <span className="relative flex items-center gap-0.5">
                        <LanguageMenu choice={choice} locale={locale} onChange={setChoice} />

                        {started && (
                            <button
                                type="button"
                                onClick={reset}
                                aria-label={copy.restart}
                                title={copy.restart}
                                className="flex size-8 items-center justify-center rounded-lg text-white/80 transition-colors hover:bg-white/15 hover:text-white"
                            >
                                <RotateCcw className="size-4" />
                            </button>
                        )}

                        <button
                            type="button"
                            onClick={hide}
                            aria-label={copy.close}
                            className="flex size-8 items-center justify-center rounded-lg text-white/80 transition-colors hover:bg-white/15 hover:text-white"
                        >
                            <X className="size-4" />
                        </button>
                    </span>
                </header>

                {leaving ? (
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <AssistantLeadForm
                            token={token}
                            locale={locale}
                            onCancel={() => setLeaving(false)}
                            onDone={(link) => {
                                setLeaving(false);
                                window.open(link ?? '/contact', link ? '_blank' : '_self');
                            }}
                        />
                    </div>
                ) : (
                    <>
                        {/* min-h-0 so this can shrink below its content and
                            scroll; flex-1 alone makes a flex child refuse to
                            go under its natural height, which pushed the
                            composer off the bottom of a short panel. */}
                        <div
                            ref={scroller}
                            onScroll={(event) => {
                                const box = event.currentTarget;

                                stick.current = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
                            }}
                            className="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-gradient-to-b from-muted/40 to-transparent"
                        >
                          <div ref={content}>
                            {!started ? (
                                <AssistantWelcome page={page} locale={locale} onPick={send} />
                            ) : (
                                <div className="flex flex-col gap-3.5 px-4 py-4">
                                    {turns.map((turn) => (
                                        <AssistantMessage key={turn.id} turn={turn} locale={locale} />
                                    ))}

                                    {pending && <AssistantTyping locale={locale} />}

                                    {/* Their question is handed back rather
                                        than lost, so trying again is one tap
                                        instead of typing it out twice. */}
                                    {failed && (
                                        <div className="rounded-xl bg-muted px-4 py-3 text-sm">
                                            <p className="text-muted-foreground">
                                                {rateLimited ? copy.slowDown : copy.failed}
                                            </p>

                                            <button
                                                type="button"
                                                onClick={() => send(failed)}
                                                className="mt-2 rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-medium transition-colors hover:bg-muted"
                                            >
                                                {copy.askAgain}: “{failed}”
                                            </button>
                                        </div>
                                    )}

                                    {!pending && suggestions.length > 0 && (
                                        <div className="flex flex-wrap gap-1.5 ps-9 pt-1">
                                            {suggestions.map((question) => (
                                                <button
                                                    key={question}
                                                    type="button"
                                                    onClick={() => send(question)}
                                                    className="animate-in rounded-full border border-border bg-background/70 px-3 py-1.5 text-xs text-muted-foreground transition-colors fade-in hover:border-primary/40 hover:bg-muted hover:text-foreground"
                                                >
                                                    {question}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}

                            <div ref={bottom} />
                          </div>
                        </div>

                        {/* Offered once the assistant has had a fair go, or the
                            moment it admits it cannot help — never before the
                            visitor has asked anything. */}
                        {escalate && (
                            <div className="flex shrink-0 flex-wrap items-center gap-2 border-t border-border/60 bg-muted/30 px-4 py-2.5">
                                <span className="text-xs text-muted-foreground">{copy.needPerson}</span>

                                {whatsapp && (
                                    <a
                                        href={whatsapp}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-1.5 rounded-full bg-[#25D366] px-2.5 py-1 text-xs font-semibold text-white transition-transform hover:scale-[1.03]"
                                    >
                                        <MessageCircle className="size-3" />
                                        {copy.whatsapp}
                                    </a>
                                )}

                                <button
                                    type="button"
                                    onClick={() => setLeaving(true)}
                                    className="flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-xs font-medium transition-colors hover:bg-muted"
                                >
                                    <UserRound className="size-3" />
                                    {copy.leaveDetails}
                                </button>
                            </div>
                        )}

                        <div className="shrink-0 border-t border-border/60 bg-card px-3 pt-3 pb-2.5">
                            <div className="flex items-end gap-1.5 rounded-2xl border border-border bg-background px-2 py-1.5 transition-shadow focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/40">
                                <textarea
                                    ref={input}
                                    value={draft}
                                    onChange={(event) => setDraft(event.target.value)}
                                    onKeyDown={onKeyDown}
                                    rows={1}
                                    maxLength={500}
                                    placeholder={copy.placeholder}
                                    aria-label={copy.placeholder}
                                    // 16px on a phone: below that iOS zooms the
                                    // page when the field is focused.
                                    className="max-h-28 min-h-8 flex-1 resize-none bg-transparent px-2 py-1.5 text-base outline-none placeholder:text-muted-foreground sm:text-sm"
                                />

                                <VoiceButton
                                    locale={locale}
                                    onText={(spoken) => setDraft((current) => `${current} ${spoken}`.trim())}
                                />

                                <button
                                    type="button"
                                    onClick={() => send(draft)}
                                    disabled={draft.trim() === '' || pending}
                                    aria-label={copy.send}
                                    className="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-primary/75 text-primary-foreground shadow-sm transition-all enabled:hover:scale-105 disabled:opacity-30"
                                >
                                    <ArrowUp className="size-4" />
                                </button>
                            </div>

                            <p className="mt-2 text-center text-[0.65rem] leading-tight text-muted-foreground">
                                {copy.footnote}
                            </p>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

/** The last thing they asked, to open WhatsApp already mid-conversation. */
function lastQuestion(turns: { role: string; text: string }[]): string | null {
    const asked = turns.filter((turn) => turn.role === 'user');

    return asked.length > 0 ? asked[asked.length - 1].text : null;
}
