import ComparisonTable from '@/components/landing/ComparisonTable';
import FeatureGrid from '@/components/landing/FeatureGrid';
import InteractiveDemo from '@/components/landing/InteractiveDemo';
import LandingNav from '@/components/landing/LandingNav';
import PanelCompatibility from '@/components/landing/PanelCompatibility';
import PaymentRail from '@/components/landing/PaymentRail';
import PhoneDemo from '@/components/landing/PhoneDemo';
import ProblemSolution from '@/components/landing/ProblemSolution';
import Reveal from '@/components/landing/Reveal';
import TryOnWhatsApp from '@/components/landing/TryOnWhatsApp';
import { Section, SectionHeading } from '@/components/landing/Section';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/react';
import {
    BadgeCheck,
    Bot,
    CheckCircle2,
    Coins,
    Headset,
    Link2,
    MessageSquare,
    Phone,
    PlayCircle,
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

type GatewaySummary = {
    code: string;
    label: string;
    type: string;
};

type Props = {
    plans: Record<string, PlanSummary>;
    gateways: GatewaySummary[];
    demoNumber: string | null;
};

/**
 * Claims that are true on day one, each against what it replaces.
 *
 * The counts that were here before — panels connected, orders processed —
 * were invented. A reseller who works out that one number is fiction stops
 * believing the rest of the page, including what it says about keeping their
 * WhatsApp number safe and their customers' money theirs. That is a bad trade
 * for a figure nobody was asked to verify.
 *
 * The `against` line is what makes these land: a reseller is not comparing us
 * with a rival, they are comparing us with their own evening. Naming that is
 * more persuasive than any number we could claim.
 */
const TRUST = [
    { value: '24/7', label: 'Your shop never closes', against: 'vs. only while you are awake' },
    { value: '~5 min', label: 'From signup to selling', against: 'vs. building a bot yourself' },
    { value: 'Instant', label: 'Orders reach your panel', against: 'vs. copy and paste, one by one' },
    { value: '$0', label: 'To start — no card', against: 'vs. paying to find out' },
];

const WHY = [
    {
        icon: ShieldCheck,
        title: 'Official Meta Cloud API',
        body: "Your number won't get banned. Competitors scan a QR code and risk the ban hammer — we use the API Meta built for this.",
    },
    {
        icon: Coins,
        title: 'Get paid your way',
        body: 'USDT and crypto for international customers, M-Pesa, Tigo and Airtel for local ones. Your gateways, your money.',
    },
    {
        icon: Bot,
        title: 'It sells and it supports',
        body: 'Most bots only answer questions. Ours takes the order, charges the wallet, and handles the refill afterwards.',
    },
];

const SERVICE_ICONS: Record<string, typeof Bot> = {
    order_bot: Bot,
    support_bot: Headset,
    ai_tickets: MessageSquare,
    ai_chat: Sparkles,
    number_rental: Phone,
};

const STEPS = [
    { icon: BadgeCheck, title: 'Sign up free', body: 'Email and phone. No card, no call.' },
    { icon: Link2, title: 'Connect your panel', body: 'Paste your URL and API key — we detect the rest.' },
    { icon: MessageSquare, title: 'Connect WhatsApp', body: 'Use your own Meta number, or rent one from us.' },
    { icon: Rocket, title: 'Start selling', body: 'Your customers order and pay without you lifting a finger.' },
];

const FAQS = [
    {
        q: 'Will my WhatsApp number get banned?',
        a: "No. We use Meta's official Cloud API — the same one large businesses use. Tools that scan a QR code are the ones that get numbers banned.",
    },
    {
        q: 'Do I need my own SMM panel?',
        a: 'Yes. This is the layer on top of your panel: it takes orders and payments from your customers and forwards them to whichever panel you already use.',
    },
    {
        q: 'How do my customers pay me?',
        a: 'Through your own gateway accounts — mobile money or crypto. The money goes to you, not through us.',
    },
    {
        q: 'Can I try it before paying?',
        a: 'Yes. Every service starts in sandbox: set it all up, and test the bot against your own number before you go live.',
    },
];

export default function Landing({ plans, gateways, demoNumber }: Props) {
    const planList = Object.entries(plans);

    return (
        <>
            <Head title="WhatsApp Bots for Your SMM Panel" />

            <LandingNav />

            <main>
                {/* ---- hero ---- */}
                <Section className="relative overflow-hidden pt-12 pb-8 sm:pt-16">
                    {/* Two soft washes behind the fold. Enough to stop the
                        canvas reading as flat white, far short of the neon
                        gradients that make a payments product look like a
                        pump-and-dump. */}
                    <div
                        aria-hidden
                        className="pointer-events-none absolute -top-40 -right-32 -z-10 size-[32rem] rounded-full bg-primary/10 blur-3xl"
                    />
                    <div
                        aria-hidden
                        className="pointer-events-none absolute -bottom-52 -left-40 -z-10 size-[28rem] rounded-full bg-accent-500/10 blur-3xl"
                    />

                    <div className="grid items-center gap-12 lg:grid-cols-2">
                        <div>
                            {/* The two fears a reseller arrives with — losing
                                the number, and paying to find out. Given the
                                brand colour and a border so they read as
                                assurances rather than decoration. */}
                            <Reveal>
                                <div className="mb-5 flex flex-wrap gap-2">
                                    <span className="flex items-center gap-1.5 rounded-full border border-primary/25 bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary">
                                        <ShieldCheck className="size-4" />
                                        Official Meta Cloud API
                                    </span>
                                    <span className="flex items-center gap-1.5 rounded-full border border-primary/25 bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary">
                                        <Coins className="size-4" />
                                        Mobile money &amp; crypto
                                    </span>
                                </div>
                            </Reveal>

                            <Reveal delay={80}>
                                <h1 className="font-heading text-4xl leading-[1.1] font-extrabold tracking-tight text-balance sm:text-5xl">
                                    WhatsApp bots for your{' '}
                                    <span className="text-primary">SMM panel</span>
                                </h1>
                            </Reveal>

                            {/* Says what the reseller gets, not how it works.
                                The mechanism is the section below; this line
                                has to earn the scroll. */}
                            <Reveal delay={160}>
                                <p className="mt-5 max-w-lg text-lg text-pretty text-muted-foreground">
                                    Sell followers, likes and views around the clock — paid by
                                    mobile money or crypto, without you lifting a finger.
                                </p>
                            </Reveal>

                            <Reveal delay={240}>
                                <div className="mt-8 flex flex-wrap gap-3">
                                    <Button size="lg" asChild>
                                        <Link href={route('register')}>
                                            <Rocket className="size-4" />
                                            Start free
                                        </Link>
                                    </Button>
                                    {/* Points at the demo rather than the price
                                        list: someone who is not sure yet wants to
                                        see it work, not to find out what it costs. */}
                                    <Button size="lg" variant="outline" asChild>
                                        <a href="#demo">
                                            <PlayCircle className="size-4" />
                                            See it work
                                        </a>
                                    </Button>
                                </div>

                                <p className="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
                                    <CheckCircle2 className="size-4 text-primary" />
                                    Free to start · No card required
                                </p>
                            </Reveal>
                        </div>

                        <Reveal delay={200}>
                            <PhoneDemo />
                        </Reveal>
                    </div>
                </Section>

                {/* ---- the ban objection ---- */}
                <Section className="pt-0 pb-14 sm:pb-16">
                    <Reveal>
                        <ProblemSolution />
                    </Reveal>
                </Section>

                {/* ---- trust bar ---- */}
                <Section muted className="py-10 sm:py-12">
                    {/* Plain divs rather than dl/dt/dd: the reveal wrapper sits
                        between the list and its terms, which the description-list
                        markup does not allow. The content is a set of claims, not
                        term/definition pairs, so nothing is lost. */}
                    <div className="grid grid-cols-2 gap-x-8 gap-y-9 text-center sm:grid-cols-4">
                        {TRUST.map((item, index) => (
                            <Reveal key={item.label} delay={index * 80}>
                                <p className="font-heading text-3xl font-extrabold text-primary">
                                    {item.value}
                                </p>
                                <p className="mt-1 text-sm font-medium">{item.label}</p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {item.against}
                                </p>
                            </Reveal>
                        ))}
                    </div>
                </Section>

                {/* ---- why us ---- */}
                <Section>
                    <SectionHeading
                        eyebrow="Why Resellers Hub"
                        title="Built for resellers, not for end customers"
                        subtitle="During a gold rush, sell shovels. You already have the customers — this is the infrastructure that serves them."
                    />

                    <div className="grid gap-6 md:grid-cols-3">
                        {WHY.map((item, index) => (
                            <Reveal key={item.title} delay={index * 100}>
                                <Card className="soft h-full border-transparent transition-transform duration-300 hover:-translate-y-1">
                                    <CardContent className="pt-6">
                                        <span className="mb-4 flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                                            <item.icon className="size-5" />
                                        </span>
                                        <h3 className="font-heading mb-2 text-lg font-bold">
                                            {item.title}
                                        </h3>
                                        <p className="text-sm leading-relaxed text-muted-foreground">
                                            {item.body}
                                        </p>
                                    </CardContent>
                                </Card>
                            </Reveal>
                        ))}
                    </div>
                </Section>

                {/* ---- services / pricing ---- */}
                <Section id="services" muted>
                    <SectionHeading
                        eyebrow="Pricing"
                        title="Pay for what you use"
                        subtitle="Every service is sold on its own. Take the order bot alone, or the lot — yearly billing saves 20%."
                    />

                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {planList.map(([key, plan], index) => {
                            const Icon = SERVICE_ICONS[key] ?? Bot;

                            return (
                                <Reveal key={key} delay={index * 90} className="h-full">
                                <Card className="soft flex h-full flex-col border-transparent transition-transform duration-300 hover:-translate-y-1">
                                    <CardContent className="flex flex-1 flex-col pt-6">
                                        <span className="mb-4 flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                                            <Icon className="size-5" />
                                        </span>

                                        <h3 className="font-heading text-lg font-bold">{plan.name}</h3>

                                        {plan.description && (
                                            <p className="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">
                                                {plan.description}
                                            </p>
                                        )}

                                        <p className="mt-5 flex items-baseline gap-1">
                                            <span className="font-heading text-3xl font-extrabold">
                                                ${plan.monthly.toFixed(0)}
                                            </span>
                                            <span className="text-sm text-muted-foreground">/month</span>
                                        </p>

                                        <p className="mt-1 text-xs text-muted-foreground">
                                            or ${plan.yearly.toFixed(2)} a year
                                        </p>

                                        <Button className="mt-5 w-full" asChild>
                                            <Link href={route('register')}>Start free</Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                                </Reveal>
                            );
                        })}
                    </div>
                </Section>

                {/* ---- interactive demo ---- */}
                <Section id="demo">
                    <SectionHeading
                        eyebrow="Try it"
                        title="Press a button. Watch it work."
                        subtitle="This is the real flow your customers get — order, support, wallet. No video, no signup."
                    />

                    <InteractiveDemo />
                </Section>

                {/* ---- how it works ---- */}
                <Section id="how" muted>
                    <SectionHeading
                        eyebrow="How it works"
                        title="Live in about five minutes"
                        subtitle="Four steps between signing up and your first automated order."
                    />

                    <ol className="grid gap-6 md:grid-cols-4">
                        {STEPS.map((step, index) => (
                            <li key={step.title} className="relative">
                                <Reveal delay={index * 110}>
                                    <span className="mb-4 flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground">
                                        <step.icon className="size-5" />
                                    </span>
                                    <p className="mb-1 text-xs font-semibold text-primary">
                                        Step {index + 1}
                                    </p>
                                    <h3 className="font-heading mb-1 font-bold">{step.title}</h3>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        {step.body}
                                    </p>
                                </Reveal>
                            </li>
                        ))}
                    </ol>
                </Section>

                {/* ---- panel compatibility ---- */}
                <Section id="panels">
                    <SectionHeading
                        eyebrow="Your panel"
                        title="It works with the panel you already have"
                        subtitle="No migration, no second panel to run. This sits on top of the one you use now."
                    />

                    <Reveal>
                        <PanelCompatibility />
                    </Reveal>
                </Section>

                {/* ---- features ---- */}
                <Section id="features">
                    <SectionHeading
                        eyebrow="Features"
                        title="Everything the shop needs to run itself"
                    />

                    <Reveal>
                        <FeatureGrid />
                    </Reveal>
                </Section>

                {/* ---- comparison ---- */}
                <Section muted>
                    <SectionHeading
                        eyebrow="Before and after"
                        title="What changes on day one"
                    />

                    <Reveal>
                        <ComparisonTable />
                    </Reveal>
                </Section>

                {/* ---- wallet explainer ---- */}
                <Section>
                    <div className="grid items-center gap-10 lg:grid-cols-2">
                        <div>
                            <SectionHeading title="Your customers pay you, not us" />
                            <p className="-mt-6 text-pretty text-muted-foreground">
                                Each customer has a wallet in your shop. They top it up with mobile
                                money or crypto through your own gateway accounts. When the balance
                                covers an order, it goes straight to your panel — no waiting, no
                                manual checking, and the money never passes through us.
                            </p>
                        </div>

                        <Card className="soft border-transparent">
                            <CardContent className="space-y-4 pt-6">
                                {[
                                    { icon: Wallet, text: 'Balance covers it → order placed instantly' },
                                    { icon: Coins, text: 'Short? → top up → order places itself' },
                                    { icon: CheckCircle2, text: 'Confirmed by the gateway, not by you' },
                                ].map((row) => (
                                    <p key={row.text} className="flex items-start gap-3 text-sm">
                                        <row.icon className="mt-0.5 size-4 shrink-0 text-primary" />
                                        <span>{row.text}</span>
                                    </p>
                                ))}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="mt-14">
                        <PaymentRail gateways={gateways} />
                    </div>
                </Section>

                {/* ---- faq ---- */}
                <Section id="faq" muted>
                    <SectionHeading eyebrow="FAQ" title="Questions worth asking" />

                    <div className="mx-auto max-w-3xl space-y-4">
                        {FAQS.map((faq, index) => (
                            <Reveal key={faq.q} delay={index * 70}>
                                <Card className="border-border/60">
                                    <CardContent className="pt-6">
                                        <h3 className="font-heading mb-2 font-bold">{faq.q}</h3>
                                        <p className="text-sm leading-relaxed text-muted-foreground">
                                            {faq.a}
                                        </p>
                                    </CardContent>
                                </Card>
                            </Reveal>
                        ))}
                    </div>
                </Section>

                {/* ---- closing cta ---- */}
                <Section>
                    <Reveal>
                        {/* Filled with the brand green rather than left on the
                            page background: this is the last thing a reader
                            passes, and it should read as an invitation rather
                            than as another paragraph. */}
                        <div className="relative overflow-hidden rounded-3xl bg-primary px-6 py-14 text-center text-primary-foreground sm:px-12">
                            <div
                                aria-hidden
                                className="pointer-events-none absolute -top-24 -right-20 size-80 rounded-full bg-white/10 blur-3xl"
                            />
                            <div
                                aria-hidden
                                className="pointer-events-none absolute -bottom-28 -left-20 size-80 rounded-full bg-black/10 blur-3xl"
                            />

                            <div className="relative">
                                <h2 className="font-heading text-3xl font-extrabold text-balance sm:text-4xl">
                                    Put your panel on WhatsApp today
                                </h2>

                                <p className="mx-auto mt-4 max-w-xl text-pretty text-primary-foreground/85">
                                    Free to start, and every service runs in sandbox until you
                                    decide to go live.
                                </p>

                                <div className="mt-8 flex flex-wrap justify-center gap-3">
                                    <Button size="lg" variant="secondary" asChild>
                                        <Link href={route('register')}>
                                            <Rocket className="size-4" />
                                            Create your account
                                        </Link>
                                    </Button>

                                    <Button
                                        size="lg"
                                        variant="outline"
                                        className="border-white/30 bg-transparent text-primary-foreground hover:bg-white/10 hover:text-primary-foreground"
                                        asChild
                                    >
                                        <a href="#demo">
                                            <PlayCircle className="size-4" />
                                            Try it first
                                        </a>
                                    </Button>
                                </div>

                                <p className="mt-5 flex items-center justify-center gap-2 text-sm text-primary-foreground/80">
                                    <CheckCircle2 className="size-4" />
                                    No card required · Cancel any time
                                </p>
                            </div>
                        </div>
                    </Reveal>
                </Section>
            </main>

            <footer className="border-t border-border/60 px-4 py-10">
                <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 text-sm text-muted-foreground sm:flex-row">
                    <p>© {new Date().getFullYear()} Resellers Hub</p>
                    <p>Built for SMM resellers · Mwanza, Tanzania</p>
                </div>
            </footer>

            <TryOnWhatsApp number={demoNumber} />
        </>
    );
}
