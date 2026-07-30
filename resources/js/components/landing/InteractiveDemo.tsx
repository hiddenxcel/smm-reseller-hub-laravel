import { Button } from '@/components/ui/button';
import { RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Bubble = {
    from: 'bot' | 'customer';
    text: string;
};

type Choice = {
    /** What the visitor's own bubble says. */
    label: string;
    /** What the bot replies. */
    reply: string;
    /** Which choices to offer next; omit to return to the main menu. */
    next?: string;
};

type Step = {
    prompt: string;
    choices: Choice[];
};

/**
 * A branching script rather than a recording: the visitor picks, and the bot
 * answers. Feeling the flow is what sells it — a video does not.
 */
const STEPS: Record<string, Step> = {
    menu: {
        prompt: '👋 Welcome to *YourPanel*!\n\nWhat would you like to do?',
        choices: [
            {
                label: '1️⃣ New order',
                reply: '✨ Which platform?\n\nInstagram · TikTok · YouTube',
                next: 'platform',
            },
            {
                label: '2️⃣ Support',
                reply: '🎧 What do you need help with?',
                next: 'support',
            },
            {
                label: '3️⃣ My balance',
                reply: '💰 *Your wallet*\n\nBalance: $9.00\nTotal spent: $41.00\n\nWant to top up?',
                next: 'topup',
            },
        ],
    },

    platform: {
        prompt: '✨ Which platform?',
        choices: [
            {
                label: 'Instagram',
                reply: '💵 *Instagram Followers*\n$2.00 per 1,000\n\nHow many?',
                next: 'quantity',
            },
            {
                label: 'TikTok',
                reply: '💵 *TikTok Views*\n$0.80 per 1,000\n\nHow many?',
                next: 'quantity',
            },
        ],
    },

    quantity: {
        prompt: 'How many?',
        choices: [
            {
                label: '500',
                reply: '🔗 Send me the link to your profile.',
                next: 'link',
            },
            {
                label: '1,000',
                reply: '🔗 Send me the link to your profile.',
                next: 'link',
            },
        ],
    },

    link: {
        prompt: 'Send the link',
        choices: [
            {
                label: 'instagram.com/mystore',
                reply: '🧾 *Confirm your order*\n\nInstagram Followers\nQuantity: 500\n*Total: $1.00*',
                next: 'confirm',
            },
        ],
    },

    confirm: {
        prompt: 'Confirm?',
        choices: [
            {
                label: '✅ Confirm',
                reply: '✅ Order *#48220* placed!\n\nCharged: $1.00\nNew balance: $8.00\n\nYour order is already on the panel. 🚀',
            },
            {
                label: '❌ Cancel',
                reply: 'No problem — cancelled. Anything else?',
            },
        ],
    },

    support: {
        prompt: 'What do you need?',
        choices: [
            {
                label: 'Refill',
                reply: '🔢 Send the *Order ID* and I will check your refill guarantee.',
                next: 'refill',
            },
            {
                label: 'Order status',
                reply: '📦 Order *#48220*\nStatus: *In progress*\nRemaining: 120',
            },
        ],
    },

    refill: {
        prompt: 'Send the order ID',
        choices: [
            {
                label: '#48220',
                reply: '♻️ Refill for *#48220* submitted!\nGuarantee: 30 days ✅',
            },
        ],
    },

    topup: {
        prompt: 'Top up?',
        choices: [
            {
                label: 'M-Pesa',
                reply: '📲 Check your phone — approve the payment prompt.\n\nYour wallet updates the moment it clears.',
            },
            {
                label: 'USDT',
                reply: '🪙 Here is your payment link.\n\nYour wallet updates as soon as the transfer confirms.',
            },
        ],
    },
};

const OPENING: Bubble[] = [
    { from: 'customer', text: 'hi' },
    { from: 'bot', text: STEPS.menu.prompt },
];

export default function InteractiveDemo() {
    const [bubbles, setBubbles] = useState<Bubble[]>(OPENING);
    const [stepKey, setStepKey] = useState<string>('menu');
    const [typing, setTyping] = useState(false);
    const chatRef = useRef<HTMLDivElement>(null);
    const timers = useRef<number[]>([]);

    useEffect(
        () => () => {
            timers.current.forEach(window.clearTimeout);
        },
        [],
    );

    useEffect(() => {
        chatRef.current?.scrollTo({
            top: chatRef.current.scrollHeight,
            behavior: 'smooth',
        });
    }, [bubbles, typing]);

    const choose = (choice: Choice) => {
        if (typing) {
            return;
        }

        setBubbles((current) => [...current, { from: 'customer', text: choice.label }]);
        setTyping(true);

        timers.current.push(
            window.setTimeout(() => {
                setTyping(false);
                setBubbles((current) => [...current, { from: 'bot', text: choice.reply }]);
                setStepKey(choice.next ?? 'menu');

                // Returning to the menu should show it again, or the visitor
                // is left with buttons and no context.
                if (! choice.next) {
                    timers.current.push(
                        window.setTimeout(() => {
                            setBubbles((current) => [
                                ...current,
                                { from: 'bot', text: STEPS.menu.prompt },
                            ]);
                        }, 900),
                    );
                }
            }, 700),
        );
    };

    const restart = () => {
        timers.current.forEach(window.clearTimeout);
        timers.current = [];
        setTyping(false);
        setBubbles(OPENING);
        setStepKey('menu');
    };

    const step = STEPS[stepKey] ?? STEPS.menu;

    return (
        <div className="mx-auto grid max-w-4xl gap-6 md:grid-cols-[1fr_auto] md:items-start">
            {/* conversation */}
            <div className="soft-lg overflow-hidden rounded-2xl border border-border bg-card">
                <div className="flex items-center justify-between gap-3 border-b border-border bg-muted/50 px-4 py-3">
                    <span className="flex items-center gap-2 text-sm font-semibold">
                        <span className="size-2 rounded-full bg-primary" aria-hidden />
                        YourPanel · Order Bot
                    </span>
                    <button
                        type="button"
                        onClick={restart}
                        className="flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <RotateCcw className="size-3.5" />
                        Start over
                    </button>
                </div>

                <div
                    ref={chatRef}
                    className="h-[360px] space-y-2.5 overflow-y-auto p-4"
                    aria-live="polite"
                    aria-label="Try the order bot"
                >
                    {bubbles.map((bubble, index) => (
                        <div
                            key={index}
                            className={bubble.from === 'bot' ? 'flex justify-start' : 'flex justify-end'}
                        >
                            <p
                                className={[
                                    'max-w-[80%] rounded-2xl px-3.5 py-2 text-sm leading-snug whitespace-pre-line',
                                    'animate-in fade-in slide-in-from-bottom-1 duration-300',
                                    bubble.from === 'bot'
                                        ? 'rounded-tl-sm bg-muted text-foreground'
                                        : 'rounded-tr-sm bg-primary text-primary-foreground',
                                ].join(' ')}
                            >
                                {bubble.text}
                            </p>
                        </div>
                    ))}

                    {typing && (
                        <div className="flex justify-start">
                            <span className="flex gap-1 rounded-2xl rounded-tl-sm bg-muted px-4 py-3">
                                {[0, 150, 300].map((delay) => (
                                    <span
                                        key={delay}
                                        className="size-1.5 animate-bounce rounded-full bg-muted-foreground/60"
                                        style={{ animationDelay: `${delay}ms` }}
                                    />
                                ))}
                            </span>
                        </div>
                    )}
                </div>
            </div>

            {/* what the visitor can press */}
            <div className="md:w-56">
                <p className="mb-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Your turn — tap one
                </p>

                <div className="flex flex-wrap gap-2 md:flex-col">
                    {step.choices.map((choice) => (
                        <Button
                            key={choice.label}
                            variant="outline"
                            disabled={typing}
                            onClick={() => choose(choice)}
                            className="justify-start md:w-full"
                        >
                            {choice.label}
                        </Button>
                    ))}
                </div>
            </div>
        </div>
    );
}
