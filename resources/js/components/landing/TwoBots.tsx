import { Card, CardContent } from '@/components/ui/card';
import {
    Bot,
    Clock,
    Headset,
    MessageSquare,
    PackageSearch,
    RefreshCw,
    ShoppingCart,
    UserRound,
    Wallet,
} from 'lucide-react';
import Reveal from './Reveal';

/**
 * The two bots, side by side.
 *
 * They were only visible in the price list before, which meant a reseller met
 * them as two line items rather than as two jobs — and could not tell whether
 * they needed one, the other or both. Selling them separately is the whole
 * pricing model, so the difference has to be legible before the prices are.
 *
 * Every capability listed is one the bot actually has: the order flow is
 * OrderBotHandler's, and the support menu is SupportAction's eight cases.
 */

const ORDER = [
    { icon: ShoppingCart, text: 'Browse services and place an order' },
    { icon: Wallet, text: 'Charge the wallet, no chasing payment' },
    { icon: Bot, text: 'Send it to your panel automatically' },
    { icon: Clock, text: 'Answer at 2am without waking you' },
];

const SUPPORT = [
    { icon: RefreshCw, text: 'Refills, against your guarantee rules' },
    { icon: PackageSearch, text: 'Order status, read from the panel' },
    { icon: MessageSquare, text: 'Cancel, speed up, partial complaints' },
    { icon: UserRound, text: 'Hand over to you when it should' },
];

export default function TwoBots() {
    return (
        <div className="grid gap-6 lg:grid-cols-2">
            <Reveal>
                <Card className="soft h-full border-transparent">
                    <CardContent className="pt-6">
                        <span className="mb-4 flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground">
                            <Bot className="size-5" />
                        </span>

                        <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                            Order Bot
                        </p>
                        <h3 className="font-heading mt-1 mb-2 text-xl font-bold">
                            It sells while you sleep
                        </h3>
                        <p className="mb-5 text-sm leading-relaxed text-muted-foreground">
                            The customer picks a service, sends the link and confirms.
                            The order is on your panel before you have read the message.
                        </p>

                        <ul className="space-y-2.5">
                            {ORDER.map((row) => (
                                <li key={row.text} className="flex items-start gap-2.5 text-sm">
                                    <row.icon className="mt-0.5 size-4 shrink-0 text-primary" />
                                    <span>{row.text}</span>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </Reveal>

            <Reveal delay={120}>
                <Card className="soft h-full border-transparent">
                    <CardContent className="pt-6">
                        <span className="mb-4 flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                            <Headset className="size-5" />
                        </span>

                        <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                            Support Bot
                        </p>
                        <h3 className="font-heading mt-1 mb-2 text-xl font-bold">
                            It answers what comes after
                        </h3>
                        <p className="mb-5 text-sm leading-relaxed text-muted-foreground">
                            Most of your messages are not new orders — they are
                            "where is it" and "it dropped". This handles those without
                            you opening the panel.
                        </p>

                        <ul className="space-y-2.5">
                            {SUPPORT.map((row) => (
                                <li key={row.text} className="flex items-start gap-2.5 text-sm">
                                    <row.icon className="mt-0.5 size-4 shrink-0 text-primary" />
                                    <span>{row.text}</span>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </Reveal>
        </div>
    );
}
