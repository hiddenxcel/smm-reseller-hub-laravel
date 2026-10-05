import PhoneFrame from '@/components/PhoneFrame';
import { Bot, Check, CheckCheck, Headset, ListChecks, Loader2, RotateCcw, Send, X } from 'lucide-react';
import { FormEvent, Fragment, ReactNode, useCallback, useEffect, useRef, useState } from 'react';

type Row = { id: string; title: string; description: string };
type Button = { id: string; title: string };

type BotEvent =
    | { type: 'text'; body: string }
    | { type: 'list'; body: string; button: string; title: string; rows: Row[] }
    | { type: 'buttons'; body: string; buttons: Button[] };

type Message =
    | { key: number; from: 'me'; text: string }
    | { key: number; from: 'bot'; event: BotEvent };

type DistributiveOmit<T, K extends keyof any> = T extends unknown ? Omit<T, K> : never;

export type SimulatorConfig = {
    endpoint: string;
    business: string;
    bots: string[];
    startingBalance: string;
    /** Visitors name their own shop; signed-in resellers already have one. */
    sendBusiness?: boolean;
};

type BotKey = 'order' | 'support';

const BOT_LABEL: Record<BotKey, { name: string; hint: string; icon: typeof Bot }> = {
    order: { name: 'Order Bot', hint: 'Sells and takes payment', icon: Bot },
    support: { name: 'Support Bot', hint: 'Refills, status, human handoff', icon: Headset },
};

/** What to type first, so nobody stares at an empty chat wondering what to say. */
const SUGGESTIONS: Record<BotKey, string[]> = {
    order: ['hi', 'menu'],
    support: ['hi', '6', '1'],
};

function csrf(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}

/** *bold* and _italic_ the way WhatsApp draws them. */
function format(text: string): ReactNode {
    return text.split(/(\*[^*\n]+\*|_[^_\n]+_)/g).map((part, index) => {
        if (part.length > 2 && part.startsWith('*') && part.endsWith('*')) {
            return <strong key={index}>{part.slice(1, -1)}</strong>;
        }
        if (part.length > 2 && part.startsWith('_') && part.endsWith('_')) {
            return <em key={index}>{part.slice(1, -1)}</em>;
        }
        return <Fragment key={index}>{part}</Fragment>;
    });
}

/**
 * A WhatsApp screen that talks to the real bot.
 *
 * Every message goes to the server, through the same handlers a customer's
 * would reach — the screen holds no script of its own. What the bot sends as a
 * list or as buttons is drawn as one, and tapping a row sends the same id the
 * WhatsApp client would.
 */
export default function WhatsAppSimulator({
    config,
    onUsed,
    className = '',
}: {
    config: SimulatorConfig;
    /** Called the first time the visitor does something other than watch. */
    onUsed?: () => void;
    className?: string;
}) {
    const [bot, setBot] = useState<BotKey>('order');
    const [messages, setMessages] = useState<Message[]>([]);
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);
    const [sample, setSample] = useState(true);
    const [balance, setBalance] = useState(config.startingBalance);
    const [openList, setOpenList] = useState<Extract<BotEvent, { type: 'list' }> | null>(null);
    const [error, setError] = useState<string | null>(null);
    const counter = useRef(0);
    const scroller = useRef<HTMLDivElement>(null);
    const used = useRef(false);

    const push = useCallback((next: DistributiveOmit<Message, 'key'>[]) => {
        setMessages((current) => [
            ...current,
            ...next.map((message) => ({ ...message, key: ++counter.current }) as Message),
        ]);
    }, []);

    const call = useCallback(
        async (selected: BotKey, text: string, extra: { reset?: boolean; opening?: boolean } = {}) => {
            setBusy(true);
            setError(null);

            try {
                const response = await fetch(config.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrf(),
                    },
                    body: JSON.stringify({
                        bot: selected,
                        text,
                        ...extra,
                        ...(config.sendBusiness ? { business: config.business } : {}),
                    }),
                });

                if (! response.ok) {
                    throw new Error(response.status === 429 ? 'Slow down a little.' : 'The demo could not answer.');
                }

                const data = (await response.json()) as {
                    events: BotEvent[];
                    sample: boolean;
                    balance: string;
                };

                // A short pause per reply: instant walls of text read as a
                // form being submitted, not as someone answering.
                for (const event of data.events) {
                    push([{ from: 'bot', event }]);
                    await new Promise((resolve) => setTimeout(resolve, 220));
                }

                setSample(data.sample);
                setBalance(data.balance);
            } catch (caught) {
                setError(caught instanceof Error ? caught.message : 'The demo could not answer.');
            } finally {
                setBusy(false);
            }
        },
        [config.endpoint, config.sendBusiness, config.business, push],
    );

    // Open (and re-open on switching bots) with the menu, as a customer would.
    const start = useCallback(
        (selected: BotKey, reset: boolean) => {
            setMessages([]);
            setOpenList(null);
            void call(selected, 'hi', { reset, opening: true });
        },
        [call],
    );

    useEffect(() => {
        start('order', true);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' });
    }, [messages, busy]);

    const send = (text: string, shown?: string) => {
        const value = text.trim();

        if (! value || busy) {
            return;
        }

        if (! used.current) {
            used.current = true;
            onUsed?.();
        }

        setOpenList(null);
        push([{ from: 'me', text: shown ?? value }]);
        setDraft('');
        void call(bot, value);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(draft);
    };

    const switchBot = (next: BotKey) => {
        if (next === bot || busy) {
            return;
        }

        setBot(next);
        start(next, false);
    };

    const info = BOT_LABEL[bot];

    return (
        <div className={`mx-auto w-full max-w-[22rem] ${className}`}>
            {/* ---- which bot ---- */}
            <div role="tablist" aria-label="Which bot to try" className="mb-3 grid grid-cols-2 gap-1 rounded-xl bg-muted p-1">
                {(Object.keys(BOT_LABEL) as BotKey[]).map((key) => {
                    const Icon = BOT_LABEL[key].icon;
                    const active = key === bot;

                    return (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            onClick={() => switchBot(key)}
                            className={[
                                'flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold transition-colors',
                                active
                                    ? 'bg-card text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            ].join(' ')}
                        >
                            <Icon className="size-4" />
                            {BOT_LABEL[key].name}
                        </button>
                    );
                })}
            </div>

            {/* ---- the phone ---- */}
            <PhoneFrame className="max-w-[21.5rem]" screenClassName="h-[37rem] sm:h-[38.5rem]">
                <>
                    <div className="flex items-center gap-2.5 bg-[#1f2c34] px-3.5 py-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                            <info.icon className="size-4" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-semibold text-white">
                                {config.business}
                            </span>
                            <span className="block text-xs text-[#8696a0]">
                                {busy ? 'typing…' : `${info.name} · online`}
                            </span>
                        </span>
                        <button
                            type="button"
                            onClick={() => start(bot, true)}
                            disabled={busy}
                            aria-label="Start over"
                            className="rounded-full p-2 text-[#8696a0] transition-colors hover:bg-white/10 hover:text-white disabled:opacity-40"
                        >
                            <RotateCcw className="size-4" />
                        </button>
                    </div>

                    {/* The one line that stops a rehearsal being mistaken for a live shop. */}
                    <p className="bg-amber-400/15 px-3 py-1.5 text-center text-[11px] leading-tight text-amber-200">
                        🧪 Practice chat · wallet {balance} ·{' '}
                        {sample ? 'sample services' : 'your services'} · nothing is charged or sent
                    </p>

                    <div ref={scroller} className="flex-1 space-y-2 overflow-y-auto px-3 py-4">
                        {messages.map((message) =>
                            message.from === 'me' ? (
                                <div key={message.key} className="flex justify-end">
                                    <p className="max-w-[82%] rounded-2xl rounded-tr-sm bg-[#005c4b] px-3 py-2 text-[13.5px] leading-snug whitespace-pre-line text-white">
                                        {message.text}
                                        <CheckCheck className="ml-1.5 inline size-3.5 text-[#53bdeb]" aria-hidden />
                                    </p>
                                </div>
                            ) : (
                                <BotBubble
                                    key={message.key}
                                    event={message.event}
                                    disabled={busy}
                                    onOpen={(event) => setOpenList(event)}
                                    onPick={(id, title) => send(id, title)}
                                />
                            ),
                        )}

                        {busy && (
                            <div className="flex justify-start">
                                <span className="flex gap-1 rounded-2xl rounded-tl-sm bg-[#1f2c34] px-3.5 py-3">
                                    {[0, 1, 2].map((dot) => (
                                        <span
                                            key={dot}
                                            className="size-1.5 animate-bounce rounded-full bg-[#8696a0]"
                                            style={{ animationDelay: `${dot * 120}ms` }}
                                        />
                                    ))}
                                </span>
                            </div>
                        )}

                        {error && <p className="text-center text-xs text-red-300">{error}</p>}
                    </div>

                    {/* quick starters */}
                    <div className="flex gap-1.5 overflow-x-auto px-3 pb-2">
                        {SUGGESTIONS[bot].map((text) => (
                            <button
                                key={text}
                                type="button"
                                disabled={busy}
                                onClick={() => send(text)}
                                className="shrink-0 rounded-full border border-white/15 px-3 py-1 text-xs text-white/80 transition-colors hover:bg-white/10 disabled:opacity-40"
                            >
                                {text}
                            </button>
                        ))}
                    </div>

                    <form onSubmit={submit} className="flex items-center gap-2 bg-[#1f2c34] px-2.5 py-2.5">
                        <input
                            value={draft}
                            onChange={(event) => setDraft(event.target.value)}
                            placeholder="Type a message"
                            maxLength={500}
                            aria-label="Message"
                            // 16px: below that iOS zooms the page when the field is focused.
                            className="min-w-0 flex-1 rounded-full bg-[#2a3942] px-4 py-2 text-base text-white placeholder:text-[#8696a0] focus:outline-none sm:text-sm"
                        />
                        <button
                            type="submit"
                            disabled={busy || ! draft.trim()}
                            aria-label="Send"
                            className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity disabled:opacity-40"
                        >
                            {busy ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                        </button>
                    </form>

                    {/* ---- the list sheet ---- */}
                    {openList && (
                        <div className="absolute inset-0 z-10 flex flex-col justify-end bg-black/50" onClick={() => setOpenList(null)}>
                            <div
                                className="max-h-[78%] overflow-y-auto rounded-t-2xl bg-[#111b21] pb-2"
                                onClick={(event) => event.stopPropagation()}
                            >
                                <div className="sticky top-0 flex items-center justify-between bg-[#111b21] px-4 py-3">
                                    <p className="text-sm font-semibold text-white">{openList.title}</p>
                                    <button
                                        type="button"
                                        onClick={() => setOpenList(null)}
                                        aria-label="Close"
                                        className="rounded-full p-1 text-[#8696a0] hover:bg-white/10"
                                    >
                                        <X className="size-4" />
                                    </button>
                                </div>

                                <ul>
                                    {openList.rows.map((row) => (
                                        <li key={row.id}>
                                            <button
                                                type="button"
                                                onClick={() => send(row.id, row.title)}
                                                className="flex w-full items-start gap-3 border-t border-white/5 px-4 py-3 text-left transition-colors hover:bg-white/5"
                                            >
                                                <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border border-[#8696a0]">
                                                    <Check className="size-2.5 opacity-0" />
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block text-sm text-white">{row.title}</span>
                                                    {row.description && (
                                                        <span className="block text-xs text-[#8696a0]">{row.description}</span>
                                                    )}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                </>
            </PhoneFrame>

            <p className="mt-4 text-center text-xs text-muted-foreground">{info.hint}</p>
        </div>
    );
}

function BotBubble({
    event,
    disabled,
    onOpen,
    onPick,
}: {
    event: BotEvent;
    disabled: boolean;
    onOpen: (event: Extract<BotEvent, { type: 'list' }>) => void;
    onPick: (id: string, title: string) => void;
}) {
    return (
        <div className="flex justify-start">
            <div className="max-w-[88%] overflow-hidden rounded-2xl rounded-tl-sm bg-[#1f2c34] text-[13.5px] leading-snug text-white/90">
                <p className="px-3 py-2 break-words whitespace-pre-line">{format(event.body)}</p>

                {event.type === 'list' && (
                    <button
                        type="button"
                        disabled={disabled}
                        onClick={() => onOpen(event)}
                        className="flex w-full items-center justify-center gap-1.5 border-t border-white/10 px-3 py-2.5 text-sm font-semibold text-[#53bdeb] transition-colors hover:bg-white/5 disabled:opacity-50"
                    >
                        <ListChecks className="size-4" />
                        {event.button}
                    </button>
                )}

                {event.type === 'buttons' &&
                    event.buttons.map((button) => (
                        <button
                            key={button.id}
                            type="button"
                            disabled={disabled}
                            onClick={() => onPick(button.id, button.title)}
                            className="block w-full border-t border-white/10 px-3 py-2.5 text-center text-sm font-semibold text-[#53bdeb] transition-colors hover:bg-white/5 disabled:opacity-50"
                        >
                            {button.title}
                        </button>
                    ))}
            </div>
        </div>
    );
}
