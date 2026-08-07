import {
    Bot,
    Check,
    Clock,
    Headset,
    MessageSquare,
    PackageSearch,
    RefreshCw,
    ShoppingCart,
    UserRound,
    Wallet,
} from 'lucide-react';
import ChatPreview from './ChatPreview';
import Reveal from './Reveal';

/**
 * The two bots, alternating sides.
 *
 * Side-by-side cards made them look like two halves of one thing and gave
 * each half a column's width to explain itself. Alternating gives each bot a
 * full row, and the switch of side stops the second row reading as a repeat
 * of the first.
 *
 * Each is shown answering rather than described: every capability listed is
 * one the bot has — the order flow is OrderBotHandler's, and the support list
 * is SupportAction's own cases.
 */

const BOTS = [
    {
        key: 'order',
        icon: Bot,
        eyebrow: 'The Order Bot',
        title: 'It sells while you sleep',
        body: 'The customer browses your services, sends the link and confirms. The order is on your panel before you have read the message.',
        points: [
            { icon: ShoppingCart, text: 'Browse services and place an order' },
            { icon: Wallet, text: 'Charge the wallet, no chasing payment' },
            { icon: Bot, text: 'Send it to your panel automatically' },
            { icon: Clock, text: 'Answer at 2am without waking you' },
        ],
        chat: {
            title: 'YourPanel · Order Bot',
            lines: [
                { from: 'customer' as const, text: '500 Instagram followers' },
                {
                    from: 'bot' as const,
                    text: '🧾 *Confirm*\nInstagram Followers\nQuantity: 500\n*Total: $1.00*',
                },
                { from: 'customer' as const, text: '✅ Confirm' },
                {
                    from: 'bot' as const,
                    text: '✅ Order *#48220* placed!\n\nCharged: $1.00\nNew balance: $9.00',
                },
            ],
        },
    },
    {
        key: 'support',
        icon: Headset,
        eyebrow: 'The Support Bot',
        title: 'It answers what comes after',
        body: 'Most of your messages are not new orders — they are "where is it" and "it dropped". This handles those without you opening the panel.',
        points: [
            { icon: RefreshCw, text: 'Refills, against your guarantee rules' },
            { icon: PackageSearch, text: 'Order status, read from the panel' },
            { icon: MessageSquare, text: 'Cancel, speed up, partial complaints' },
            { icon: UserRound, text: 'Hand over to you when it should' },
        ],
        chat: {
            title: 'YourPanel · Support',
            lines: [
                { from: 'customer' as const, text: 'my followers dropped' },
                {
                    from: 'bot' as const,
                    text: '🔢 Send the *Order ID* and I will check your refill guarantee.',
                },
                { from: 'customer' as const, text: '#48220' },
                {
                    from: 'bot' as const,
                    text: '♻️ Refill for *#48220* submitted!\nGuarantee: 30 days ✅',
                },
            ],
        },
    },
];

export default function TwoBots() {
    return (
        <div className="space-y-16 lg:space-y-24">
            {BOTS.map((bot, index) => {
                // The second row leads with the chat on desktop, so the eye
                // crosses the page rather than running down one edge.
                const chatFirst = index % 2 === 1;

                return (
                    <div
                        key={bot.key}
                        className="grid items-center gap-10 lg:grid-cols-2 lg:gap-16"
                    >
                        <Reveal className={chatFirst ? 'lg:order-2' : ''}>
                            <p className="mb-3 flex items-center gap-2 text-sm font-semibold text-primary">
                                <bot.icon className="size-4" />
                                {bot.eyebrow}
                            </p>

                            <h3 className="font-heading text-2xl font-extrabold text-balance sm:text-3xl">
                                {bot.title}
                            </h3>

                            <p className="mt-4 text-pretty text-muted-foreground">{bot.body}</p>

                            <ul className="mt-6 space-y-3">
                                {bot.points.map((point) => (
                                    <li key={point.text} className="flex items-start gap-3">
                                        <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                            <Check className="size-3 text-primary" />
                                        </span>
                                        <span className="text-sm">{point.text}</span>
                                    </li>
                                ))}
                            </ul>
                        </Reveal>

                        <Reveal delay={120} className={chatFirst ? 'lg:order-1' : ''}>
                            <ChatPreview title={bot.chat.title} lines={bot.chat.lines} />
                        </Reveal>
                    </div>
                );
            })}
        </div>
    );
}
