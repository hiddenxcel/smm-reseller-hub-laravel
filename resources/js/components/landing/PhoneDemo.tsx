import { useEffect, useRef, useState } from 'react';

type Message = {
    from: 'bot' | 'customer';
    text: string;
    /** Pause before this message appears, in ms. */
    delay: number;
};

/**
 * The order flow, played back as a WhatsApp conversation. This is the pitch:
 * a reseller sees their own customer being served without them touching
 * anything.
 */
const SCRIPT: Message[] = [
    { from: 'customer', text: 'hi', delay: 600 },
    {
        from: 'bot',
        text: '👑 *WELCOME!*\n\nI can grow your socials — followers, likes and views. 🚀\n\n👇 Choose an option:',
        delay: 900,
    },
    { from: 'customer', text: '🛒 New Order', delay: 1400 },
    { from: 'bot', text: '✨ Which platform?\n\nInstagram · TikTok · YouTube', delay: 900 },
    { from: 'customer', text: 'Instagram', delay: 1200 },
    { from: 'bot', text: '💵 Instagram Followers\n$2.00 per 1,000', delay: 900 },
    { from: 'customer', text: '500 Followers', delay: 1200 },
    { from: 'bot', text: '🔗 Send me the link to your profile.', delay: 800 },
    { from: 'customer', text: 'instagram.com/mystore', delay: 1400 },
    {
        from: 'bot',
        text: '🧾 *Confirm*\nInstagram Followers\nQuantity: 500\n*Total: $1.00*',
        delay: 1000,
    },
    { from: 'customer', text: '✅ Confirm', delay: 1200 },
    {
        from: 'bot',
        text: '✅ Order *#48220* placed!\n\nCharged: $1.00\nNew balance: $9.00\n\nSend *hi* to order again.',
        delay: 1100,
    },
];

/** How long the finished conversation sits before replaying. */
const REPLAY_PAUSE = 3200;

export default function PhoneDemo() {
    const [visible, setVisible] = useState<Message[]>([SCRIPT[0]]);
    const [typing, setTyping] = useState(false);
    const [clock, setClock] = useState('09:41');
    const chatRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const tick = () =>
            setClock(
                new Date().toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                }),
            );

        tick();
        const timer = window.setInterval(tick, 30_000);

        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        let cancelled = false;
        const timers: number[] = [];

        const wait = (ms: number) =>
            new Promise<void>((resolve) => {
                timers.push(window.setTimeout(resolve, ms));
            });

        const play = async () => {
            while (!cancelled) {
                // Opens on the customer's "hi" rather than on nothing. An
                // empty panel — on first paint and again between replays —
                // looks like a demo that failed to load.
                setVisible([SCRIPT[0]]);

                for (const message of SCRIPT.slice(1)) {
                    if (cancelled) return;

                    // Only the bot "types" — a customer's message just lands.
                    if (message.from === 'bot') {
                        setTyping(true);
                        await wait(message.delay);
                        if (cancelled) return;
                        setTyping(false);
                    } else {
                        await wait(message.delay);
                        if (cancelled) return;
                    }

                    setVisible((current) => [...current, message]);
                }

                await wait(REPLAY_PAUSE);
            }
        };

        void play();

        return () => {
            cancelled = true;
            timers.forEach(window.clearTimeout);
        };
    }, []);

    useEffect(() => {
        chatRef.current?.scrollTo({
            top: chatRef.current.scrollHeight,
            behavior: 'smooth',
        });
    }, [visible, typing]);

    return (
        <div className="soft-lg mx-auto w-full max-w-[320px] rounded-[2.5rem] border border-border bg-card p-3">
            <div className="overflow-hidden rounded-[2rem] bg-[#0b141a]">
                {/* status bar */}
                <div className="flex items-center justify-between px-5 py-2 text-[11px] font-medium text-white/80">
                    <span>{clock}</span>
                    <span className="flex gap-1">
                        <span aria-hidden>▮</span>
                        <span aria-hidden>◗</span>
                        <span aria-hidden>▮</span>
                    </span>
                </div>

                {/* chat header */}
                <div className="flex items-center gap-3 bg-[#1f2c34] px-4 py-3">
                    <span
                        className="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-500 text-lg"
                        aria-hidden
                    >
                        💬
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold text-white">
                            YourPanel · Order Bot
                        </span>
                        <span className="block text-xs text-brand-100">online</span>
                    </span>
                </div>

                {/* messages */}
                {/* Stacked from the bottom, the way a real chat sits. Anchored
                    to the top, the first message hung under an empty panel and
                    the phone read as broken rather than as waiting. */}
                {/* Shorter on a phone: at 380px the visible area was mostly
                    empty dark panel between replays, which reads as a demo
                    that failed to load rather than one about to start. */}
                <div
                    ref={chatRef}
                    className="flex h-[300px] flex-col justify-end space-y-2 overflow-y-auto bg-[#0b141a] px-3 py-4 sm:h-[380px]"
                    aria-live="polite"
                    aria-label="Example conversation with the order bot"
                >
                    {visible.map((message, index) => (
                        <Bubble key={index} message={message} />
                    ))}

                    {typing && <TypingBubble />}
                </div>
            </div>
        </div>
    );
}

function Bubble({ message }: { message: Message }) {
    const fromBot = message.from === 'bot';

    return (
        <div className={fromBot ? 'flex justify-start' : 'flex justify-end'}>
            <p
                className={[
                    'max-w-[85%] whitespace-pre-line rounded-2xl px-3 py-2 text-[13px] leading-snug',
                    'animate-in fade-in slide-in-from-bottom-1 duration-300',
                    fromBot
                        ? 'rounded-tl-sm bg-[#1f2c34] text-white/90'
                        : 'rounded-tr-sm bg-[#005c4b] text-white',
                ].join(' ')}
            >
                {message.text}
            </p>
        </div>
    );
}

function TypingBubble() {
    return (
        <div className="flex justify-start">
            <span className="flex gap-1 rounded-2xl rounded-tl-sm bg-[#1f2c34] px-4 py-3">
                {[0, 150, 300].map((delay) => (
                    <span
                        key={delay}
                        className="size-1.5 animate-bounce rounded-full bg-white/50"
                        style={{ animationDelay: `${delay}ms` }}
                    />
                ))}
            </span>
        </div>
    );
}
