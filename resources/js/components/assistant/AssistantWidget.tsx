import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { ArrowUp, MessageCircle, RotateCcw, Sparkles, UserRound, X } from 'lucide-react';
import { KeyboardEvent, useEffect, useRef, useState } from 'react';
import AssistantBubble from './AssistantBubble';
import AssistantLeadForm from './AssistantLeadForm';
import AssistantMessage, { AssistantTyping } from './AssistantMessage';
import AssistantWelcome from './AssistantWelcome';
import { t } from './strings';
import { useAssistant } from './useAssistant';

/**
 * The ResellersHub assistant.
 *
 * A visitor with a question about the bots has, until now, had two options:
 * leave the site for WhatsApp, or find the answer themselves. Most did
 * neither. This answers in place, from written answers and the live price
 * list, and hands over to a person when it cannot.
 *
 * It replaces the floating WhatsApp button rather than joining it. Two things
 * hovering over the same corner is noise, and WhatsApp is better placed here
 * anyway — offered after the assistant has failed, rather than before the
 * visitor has asked.
 *
 * Server-rendered pages mount this, so nothing here may touch the browser
 * during render; see `mounted`.
 */
export default function AssistantWidget({ demoNumber }: { demoNumber?: string | null }) {
    const { url } = usePage();
    const page = url.split('?')[0] || '/';

    const [mounted, setMounted] = useState(false);
    const [open, setOpen] = useState(false);
    const [leaving, setLeaving] = useState(false);
    const [unread, setUnread] = useState(false);
    const [draft, setDraft] = useState('');

    const { turns, suggestions, pending, escalate, failed, locale, ask, reset, token, started } =
        useAssistant(page);

    // Everything the widget says in its own voice follows the conversation's
    // language, so the answers and the furniture around them never disagree.
    const copy = t(locale);

    const bottom = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLTextAreaElement>(null);
    const answered = useRef(0);

    // The panel must not exist in the server-rendered HTML at all: it reads
    // sessionStorage and measures the viewport, neither of which exists in
    // Node, and a mismatch here would be visible on first paint.
    useEffect(() => setMounted(true), []);

    // Follows the conversation down. Auto rather than smooth while a reply is
    // arriving, so a long answer does not scroll for the length of its own
    // reveal.
    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: pending ? 'auto' : 'smooth' });
    }, [turns, pending, suggestions]);

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
              lastQuestion(turns) ?? 'Hi, I have a question about ResellersHub',
          )}`
        : null;

    return (
        <>
            <AssistantBubble open={open} unread={unread} locale={locale} onOpen={show} />

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
                aria-label="ResellersHub assistant"
                className={cn(
                    'fixed z-50 flex flex-col overflow-hidden border-border bg-card shadow-2xl transition-all duration-200',
                    // Phone: a sheet filling most of the screen, full width. A
                    // 400px panel on a 390px screen is not a panel. It stops
                    // short of the top so the page behind stays visible —
                    // covering everything reads as having navigated away.
                    'inset-x-0 bottom-0 top-16 rounded-t-2xl border-t',
                    // Desktop: a panel in the corner that grows with the
                    // conversation instead of standing at full height from the
                    // first word. A tall box holding two lines and a wall of
                    // empty space looks broken, which is what it was doing.
                    'sm:inset-auto sm:right-6 sm:bottom-6 sm:top-auto sm:max-h-[min(36rem,calc(100dvh-7rem))] sm:w-[25rem] sm:rounded-2xl sm:border',
                    open
                        ? 'translate-y-0 opacity-100 sm:scale-100'
                        : 'pointer-events-none translate-y-4 opacity-0 sm:origin-bottom-right sm:translate-y-0 sm:scale-95',
                )}
            >
                <header className="flex items-center gap-3 border-b border-border/60 bg-card/80 px-4 py-3 backdrop-blur">
                    <span className="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-primary to-primary/75 text-primary-foreground">
                        <Sparkles className="size-4" />
                    </span>

                    <span className="flex-1">
                        <span className="block text-sm leading-tight font-bold">
                            {copy.title}
                        </span>
                        <span className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span className="relative flex size-1.5">
                                <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500/70" />
                                <span className="relative inline-flex size-1.5 rounded-full bg-emerald-500" />
                            </span>
                            {copy.status}
                        </span>
                    </span>

                    {started && (
                        <button
                            type="button"
                            onClick={reset}
                            aria-label={copy.restart}
                            className="flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        >
                            <RotateCcw className="size-4" />
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={hide}
                        aria-label={copy.close}
                        className="flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <X className="size-4" />
                    </button>
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
                        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                            {!started ? (
                                <AssistantWelcome page={page} locale={locale} onPick={send} />
                            ) : (
                                <div className="flex flex-col gap-3 px-4 py-4">
                                    {turns.map((turn) => (
                                        <AssistantMessage key={turn.id} turn={turn} />
                                    ))}

                                    {pending && <AssistantTyping locale={locale} />}

                                    {/* Their question is handed back rather
                                        than lost, so trying again is one tap
                                        instead of typing it out twice. */}
                                    {failed && (
                                        <div className="rounded-xl bg-muted px-4 py-3 text-sm">
                                            <p className="text-muted-foreground">
                                                {copy.failed}
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
                                        <div className="flex flex-wrap gap-1.5 pt-1">
                                            {suggestions.map((question) => (
                                                <button
                                                    key={question}
                                                    type="button"
                                                    onClick={() => send(question)}
                                                    className="animate-in rounded-full border border-border px-3 py-1.5 text-xs text-muted-foreground transition-colors fade-in hover:bg-muted hover:text-foreground"
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

                        {/* Offered once the assistant has had a fair go, or the
                            moment it admits it cannot help — never before the
                            visitor has asked anything. */}
                        {escalate && (
                            <div className="flex items-center gap-2 border-t border-border/60 px-4 py-2.5">
                                <span className="text-xs text-muted-foreground">
                                    {copy.needPerson}
                                </span>

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
                                    className="flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs font-medium transition-colors hover:bg-muted"
                                >
                                    <UserRound className="size-3" />
                                    {copy.leaveDetails}
                                </button>
                            </div>
                        )}

                        <div className="border-t border-border/60 px-3 py-3">
                            <div className="flex items-end gap-2 rounded-2xl border border-border bg-background px-3 py-2 focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50">
                                <textarea
                                    ref={input}
                                    value={draft}
                                    onChange={(event) => setDraft(event.target.value)}
                                    onKeyDown={onKeyDown}
                                    rows={1}
                                    maxLength={500}
                                    placeholder={copy.placeholder}
                                    className="max-h-24 flex-1 resize-none bg-transparent py-1 text-sm outline-none placeholder:text-muted-foreground"
                                />

                                <button
                                    type="button"
                                    onClick={() => send(draft)}
                                    disabled={draft.trim() === '' || pending}
                                    aria-label={copy.send}
                                    className="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-primary/75 text-primary-foreground transition-opacity disabled:opacity-30"
                                >
                                    <ArrowUp className="size-4" />
                                </button>
                            </div>

                            <p className="mt-2 text-center text-[0.65rem] text-muted-foreground">
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
