import {
    Activity,
    Bot,
    Gift,
    Globe,
    Headset,
    KeyRound,
    LayoutDashboard,
    MessageSquare,
    PackageSearch,
    RefreshCw,
    ShieldAlert,
    ShoppingCart,
    SlidersHorizontal,
    Sparkles,
    TestTube2,
    UserRound,
    Wallet,
} from 'lucide-react';
import Reveal from './Reveal';

/**
 * What each bot does, written from what the handlers actually do.
 *
 * Cancel, speed-up and partial reports are said to reach the reseller rather
 * than to be carried out: the support bot checks the order and tells staff, it
 * does not cancel or compensate on the panel by itself. A page that promised
 * more would be found out on the first complaint.
 */

type Item = { icon: typeof Bot; title: string; body: string };

const ORDER_BOT: Item[] = [
    { icon: ShoppingCart, title: 'New order', body: 'Platform, category, service, quantity, link, confirm. Placed on your panel on its own.' },
    { icon: Wallet, title: 'Wallet top-up', body: 'Pays through your gateway. The balance moves when the gateway confirms.' },
    { icon: PackageSearch, title: 'Track orders', body: 'The customer sees their recent orders without asking you.' },
    { icon: Gift, title: 'Profile and referrals', body: 'Balance, total spent, their referral code and what it has earned.' },
    { icon: Globe, title: 'Five languages', body: 'English, French, Kiswahili, Turkish and Hindi, chosen per customer.' },
    { icon: Sparkles, title: 'AI questions', body: 'Anything the menu does not cover, answered in the same chat (AI add-on).' },
];

const SUPPORT_BOT: Item[] = [
    { icon: RefreshCw, title: 'Refill', body: 'Checked against your guarantee rules, then sent to the panel if it is owed.' },
    { icon: PackageSearch, title: 'Order status', body: 'Status, start count and remaining, read live from your panel.' },
    { icon: MessageSquare, title: 'Cancel, speed-up, partial', body: 'Logged and passed to you with the order ID. You decide what happens.' },
    { icon: UserRound, title: 'Talk to a human', body: 'The bot steps back and you reply from your inbox, on the same number.' },
    { icon: Wallet, title: 'Top-up problems', body: 'Collects the amount, reference and time so you can credit the wallet.' },
    { icon: Sparkles, title: 'AI FAQ', body: 'Answers questions about your services and prices (AI add-on).' },
];

const UNDER_THE_HOOD: Item[] = [
    { icon: LayoutDashboard, title: 'Shared inbox', body: 'Every handoff lands in one place with the whole thread.' },
    { icon: Activity, title: 'Panel health alerts', body: 'Told when a panel stops answering, comes back, or runs low on balance.' },
    { icon: SlidersHorizontal, title: 'Price rules', body: 'Set margins once, apply in bulk, and see the price history.' },
    { icon: ShieldAlert, title: 'Anti-spam', body: 'Repeat senders are paused automatically. Your staff numbers are exempt.' },
    { icon: TestTube2, title: 'Sandbox and test numbers', body: 'Try the whole bot on your own phone before a customer sees it.' },
    { icon: KeyRound, title: 'API and logs', body: 'Place orders and read status from your own site, with request logs.' },
];

function Column({ title, subtitle, icon: Icon, items, offset }: {
    title: string;
    subtitle: string;
    icon: typeof Bot;
    items: Item[];
    offset: number;
}) {
    return (
        <div className="card-surface rounded-3xl p-5 sm:p-7">
            <div className="mb-6 flex items-center gap-3">
                <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-md shadow-primary/25">
                    <Icon className="size-6" />
                </span>
                <div>
                    <h3 className="font-heading text-xl font-extrabold">{title}</h3>
                    <p className="text-sm text-muted-foreground">{subtitle}</p>
                </div>
            </div>

            <ul className="grid gap-2.5">
                {items.map((item, index) => (
                    <Reveal key={item.title} delay={(index + offset) * 50}>
                        <li className="flex items-start gap-3 rounded-xl border border-border/60 bg-background/60 p-3.5 transition-colors hover:border-primary/40">
                            <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <item.icon className="size-4" />
                            </span>
                            <div>
                                <p className="text-sm font-semibold">{item.title}</p>
                                <p className="mt-0.5 text-sm leading-relaxed text-muted-foreground">
                                    {item.body}
                                </p>
                            </div>
                        </li>
                    </Reveal>
                ))}
            </ul>
        </div>
    );
}

export default function Capabilities() {
    return (
        <div>
            <div className="grid gap-5 lg:grid-cols-2 lg:gap-6">
                <Reveal>
                    <Column
                        title="Order Bot"
                        subtitle="Sells, takes payment, answers"
                        icon={Bot}
                        items={ORDER_BOT}
                        offset={0}
                    />
                </Reveal>
                <Reveal delay={100}>
                    <Column
                        title="Support Bot"
                        subtitle="Handles everything after the sale"
                        icon={Headset}
                        items={SUPPORT_BOT}
                        offset={0}
                    />
                </Reveal>
            </div>

            <Reveal>
                <p className="mt-12 mb-5 text-center text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                    And behind both
                </p>
            </Reveal>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-3">
                {UNDER_THE_HOOD.map((item, index) => (
                    <Reveal key={item.title} delay={index * 60} index={index} card className="h-full">
                        <div className="card-surface flex h-full items-start gap-3.5 rounded-2xl p-4 sm:p-5">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent text-accent-foreground ring-1 ring-primary/10">
                                <item.icon className="size-5" />
                            </span>
                            <div>
                                <h4 className="font-heading font-bold">{item.title}</h4>
                                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                                    {item.body}
                                </p>
                            </div>
                        </div>
                    </Reveal>
                ))}
            </div>
        </div>
    );
}
