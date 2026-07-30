import {
    BarChart3,
    Bot,
    Gift,
    Languages,
    Layers,
    MessageSquare,
    Megaphone,
    ShieldCheck,
    Wallet,
} from 'lucide-react';

/**
 * Only things that exist. A feature grid that promises what is not built is
 * how a first customer arrives disappointed.
 */
const FEATURES = [
    {
        icon: Layers,
        title: 'Multiple panels',
        body: 'Connect more than one SMM panel and sell from all of them through the same bot.',
    },
    {
        icon: Bot,
        title: 'Orders without you',
        body: 'Platform, service, quantity, link, confirm — then straight onto your panel.',
    },
    {
        icon: ShieldCheck,
        title: 'Official Cloud API',
        body: "Meta's own API, so your number is not at risk the way QR-scanning tools are.",
    },
    {
        icon: Wallet,
        title: 'Customer wallets',
        body: 'Each customer holds a balance. Orders debit it instantly — no chasing payments.',
    },
    {
        icon: MessageSquare,
        title: 'Refills and status',
        body: 'Your guarantee rules decide who gets a refill, and the panel answers status questions.',
    },
    {
        icon: Languages,
        title: 'Five languages',
        body: 'English, French, Kiswahili, Turkish and Hindi — each customer in their own.',
    },
    {
        icon: Megaphone,
        title: 'Broadcast',
        body: "Message customers who wrote to you recently, within Meta's 24-hour window.",
    },
    {
        icon: Gift,
        title: 'Referrals',
        body: 'Reward customers who bring you more customers, as a share of their first deposit.',
    },
    {
        icon: BarChart3,
        title: 'Orders and revenue',
        body: 'See what sold, what it earned, and which customers keep coming back.',
    },
];

export default function FeatureGrid() {
    return (
        <div className="grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
            {FEATURES.map((feature) => (
                <div key={feature.title}>
                    <span className="mb-3 flex size-10 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                        <feature.icon className="size-5" />
                    </span>
                    <h3 className="font-heading mb-1.5 font-bold">{feature.title}</h3>
                    <p className="text-sm leading-relaxed text-muted-foreground">{feature.body}</p>
                </div>
            ))}
        </div>
    );
}
