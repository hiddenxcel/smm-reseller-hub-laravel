import ChatPreview from '@/components/landing/ChatPreview';
import Reveal from '@/components/landing/Reveal';
import { Section, SectionHeading } from '@/components/landing/Section';
import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import Seo from '@/components/Seo';
import {
    BarChart3,
    Bot,
    Check,
    Gift,
    Headset,
    Languages,
    Layers,
    Megaphone,
    MessageSquare,
    Phone,
    Rocket,
    ShieldCheck,
    Sparkles,
    Wallet,
} from 'lucide-react';

type PlanSummary = {
    name: string;
    description: string | null;
    monthly: number;
    yearly: number;
    currency: string;
};

type Props = {
    plans: Record<string, PlanSummary>;
    demoNumber: string | null;
};

/**
 * Every service, at length.
 *
 * The landing page has to earn a scroll in five seconds, so it shows two bots
 * and moves on. Someone who has clicked Features has already decided to read,
 * and the question they arrive with is narrower: does it do the specific
 * thing my shop needs. So each service gets its own row, and each row lists
 * what it actually does rather than what it is for.
 *
 * Everything here exists in the code. A features page that promises what is
 * not built is how a first customer arrives disappointed.
 */

const SERVICES = [
    {
        key: 'order_bot',
        icon: Bot,
        name: 'Order Bot',
        tagline: 'It sells while you sleep',
        body: 'Your customer browses your services, picks a quantity, sends the link and confirms. The wallet is charged and the order reaches your panel without you seeing the message.',
        points: [
            'Browse by platform and service, priced from your panel',
            'Quantity checked against the service minimum and maximum',
            'Wallet charged before the order is placed, never after',
            'Order forwarded to the right panel when you run several',
            'Confirmation with the order ID and the new balance',
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
        key: 'support_bot',
        icon: Headset,
        name: 'Support Bot',
        tagline: 'It answers what comes after',
        body: 'Most messages are not new orders. They are "where is my order" and "the followers dropped" — and each one costs you an evening. This answers them from the panel.',
        points: [
            'Refills, checked against your own guarantee window',
            'Order status, read live from the panel',
            'Cancel and speed-up requests',
            'Partial and fake-complaint handling',
            'Top-up problems, and a route to you when it should',
            'Each action can be switched off in your settings',
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
    {
        key: 'ai_chat',
        icon: Sparkles,
        name: 'AI Chat',
        tagline: 'For the questions no menu covers',
        body: 'A menu answers what you predicted. AI Chat answers the rest — inside the same WhatsApp conversation, without handing the customer to a different channel.',
        points: [
            'Answers in the customer’s own language',
            'Runs inside the order bot, not as a separate number',
            'Falls back to the menu when a real action is needed',
        ],
        chat: {
            title: 'YourPanel · Order Bot',
            lines: [
                { from: 'customer' as const, text: 'do you have TikTok views?' },
                {
                    from: 'bot' as const,
                    text: '✨ Yes — TikTok Views, $0.80 per 1,000.\n\nWant me to start an order?',
                },
            ],
        },
    },
    {
        key: 'ai_tickets',
        icon: MessageSquare,
        name: 'AI Tickets',
        tagline: 'Support on your own website',
        body: 'Not every customer arrives on WhatsApp. AI Tickets puts the same answers on your site, so the people who came through your panel get served too.',
        points: [
            'A ticket desk that lives on your own domain',
            'Answers drafted from what your bot already knows',
            'Anything it cannot settle comes to you',
        ],
        chat: null,
    },
    {
        key: 'number_rental',
        icon: Phone,
        name: 'Rent a Number',
        tagline: 'Start before Meta approves you',
        body: 'The Cloud API needs a Meta Business account, and getting one takes days. Rent a number and your bot answers today — on the same official API, not a workaround.',
        points: [
            'No Meta Business account needed to start',
            'Live the day you rent it',
            'Official Cloud API, exactly as your own number would be',
            'Move to your own number whenever you are approved',
        ],
        chat: null,
    },
];

const EVERYTHING = [
    { icon: Layers, title: 'Multiple panels', body: 'Sell from every panel you run through one bot.' },
    { icon: Wallet, title: 'Customer wallets', body: 'Each customer holds a balance that orders debit instantly.' },
    { icon: ShieldCheck, title: 'Official Cloud API', body: "Meta's own API — your number is not at risk." },
    { icon: Languages, title: 'Five languages', body: 'English, French, Kiswahili, Turkish and Hindi.' },
    { icon: Megaphone, title: 'Broadcast', body: "Message recent customers inside Meta's 24-hour window." },
    { icon: Gift, title: 'Referrals', body: 'Reward customers who bring you more customers.' },
    { icon: BarChart3, title: 'Orders and revenue', body: 'What sold, what it earned, who keeps coming back.' },
    { icon: Bot, title: 'API access', body: 'Place orders from your own site or software.' },
];

export default function Features({ plans, demoNumber }: Props) {
    return (
        <PublicLayout
            eyebrow="Features"
            title="Everything the shop needs to run itself"
            description="Five services, sold separately. Take the one that solves today's problem and add the rest when it becomes tomorrow's."
            demoNumber={demoNumber}
        >
            <Seo
                title="Features"
                description="Every service in the Resellers Hub platform: the WhatsApp order bot, the support bot, AI chat, AI tickets and rented Cloud API numbers — with what each one actually does."
            />

            {/* ---- each service in turn ---- */}
            <Section className="pt-0">
                <div className="space-y-20 lg:space-y-28">
                    {SERVICES.map((service, index) => {
                        const plan = plans[service.key];
                        const chatFirst = index % 2 === 1;

                        return (
                            <div
                                key={service.key}
                                id={service.key}
                                className="grid items-center gap-10 lg:grid-cols-2 lg:gap-16"
                            >
                                <Reveal className={chatFirst ? 'lg:order-2' : ''}>
                                    <p className="mb-3 flex items-center gap-2 text-sm font-semibold text-primary">
                                        <service.icon className="size-4" />
                                        {service.name}
                                    </p>

                                    <h2 className="font-heading text-2xl font-extrabold text-balance sm:text-3xl">
                                        {service.tagline}
                                    </h2>

                                    <p className="mt-4 text-pretty text-muted-foreground">
                                        {service.body}
                                    </p>

                                    <ul className="mt-6 space-y-2.5">
                                        {service.points.map((point) => (
                                            <li key={point} className="flex items-start gap-3">
                                                <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                                    <Check className="size-3 text-primary" />
                                                </span>
                                                <span className="text-sm">{point}</span>
                                            </li>
                                        ))}
                                    </ul>

                                    {plan && (
                                        <p className="mt-6 text-sm text-muted-foreground">
                                            From{' '}
                                            <span className="font-heading text-lg font-bold text-foreground">
                                                ${plan.monthly.toFixed(0)}
                                            </span>{' '}
                                            a month ·{' '}
                                            <Link
                                                href={route('pricing')}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                see pricing
                                            </Link>
                                        </p>
                                    )}
                                </Reveal>

                                <Reveal delay={120} className={chatFirst ? 'lg:order-1' : ''}>
                                    {service.chat ? (
                                        <ChatPreview
                                            title={service.chat.title}
                                            lines={service.chat.lines}
                                        />
                                    ) : (
                                        <div className="rounded-3xl border border-primary/20 bg-primary/5 p-8">
                                            <span className="mb-4 flex size-12 items-center justify-center rounded-2xl bg-primary text-primary-foreground">
                                                <service.icon className="size-6" />
                                            </span>

                                            <p className="font-heading text-lg font-bold">
                                                {service.name}
                                            </p>
                                            <p className="mt-1.5 text-sm text-muted-foreground">
                                                {plan?.description ?? service.tagline}
                                            </p>
                                        </div>
                                    )}
                                </Reveal>
                            </div>
                        );
                    })}
                </div>
            </Section>

            {/* ---- the rest ---- */}
            <Section muted>
                <SectionHeading
                    eyebrow="And throughout"
                    title="The parts every service shares"
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {EVERYTHING.map((item, index) => (
                        <Reveal key={item.title} delay={index * 60} index={index} card className="h-full">
                            <div className="group flex h-full flex-col rounded-2xl border border-border bg-card p-5 transition-all duration-300 hover:-translate-y-1 hover:border-primary/40">
                                <span className="mb-3 flex size-10 items-center justify-center rounded-xl bg-accent text-accent-foreground transition-colors duration-300 group-hover:bg-primary group-hover:text-primary-foreground">
                                    <item.icon className="size-5" />
                                </span>

                                <h3 className="font-heading mb-1.5 font-bold">{item.title}</h3>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    {item.body}
                                </p>
                            </div>
                        </Reveal>
                    ))}
                </div>
            </Section>

            {/* ---- closing ---- */}
            <Section>
                <Reveal>
                    <div className="relative overflow-hidden rounded-3xl bg-primary px-6 py-14 text-center text-primary-foreground sm:px-12">
                        <div
                            aria-hidden
                            className="pointer-events-none absolute -top-24 -right-20 size-80 rounded-full bg-white/10 blur-3xl"
                        />

                        <div className="relative">
                            <h2 className="font-heading text-3xl font-extrabold text-balance sm:text-4xl">
                                Start with one. Add the rest later.
                            </h2>

                            <p className="mx-auto mt-4 max-w-xl text-pretty text-primary-foreground/85">
                                Every service runs in sandbox until you decide to go live,
                                so nothing reaches a customer before you have tested it.
                            </p>

                            <div className="mt-8 flex flex-wrap justify-center gap-3">
                                <Button size="lg" variant="secondary" asChild>
                                    <Link href={route('register')}>
                                        <Rocket className="size-4" />
                                        Start free
                                    </Link>
                                </Button>

                                <Button
                                    size="lg"
                                    variant="outline"
                                    className="border-white/30 bg-transparent text-primary-foreground hover:bg-white/10 hover:text-primary-foreground"
                                    asChild
                                >
                                    <Link href={route('pricing')}>See pricing</Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                </Reveal>
            </Section>
        </PublicLayout>
    );
}
