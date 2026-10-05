import Capabilities from '@/components/landing/Capabilities';
import ComparisonTable from '@/components/landing/ComparisonTable';
import Faq from '@/components/landing/Faq';
import FeatureGrid from '@/components/landing/FeatureGrid';
import MoneyFlow from '@/components/landing/MoneyFlow';
import InteractiveDemo from '@/components/landing/InteractiveDemo';
import LandingNav from '@/components/landing/LandingNav';
import PanelCompatibility from '@/components/landing/PanelCompatibility';
import PaymentRail from '@/components/landing/PaymentRail';
import PhoneDemo from '@/components/landing/PhoneDemo';
import PricingTable from '@/components/landing/PricingTable';
import PublicFooter from '@/components/landing/PublicFooter';
import Reveal from '@/components/landing/Reveal';
import TwoBots from '@/components/landing/TwoBots';
import AssistantWidget from '@/components/assistant/AssistantWidget';
import { Section, SectionHeading } from '@/components/landing/Section';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    Bot,
    CheckCircle2,
    Clock,
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
    X,
    Zap,
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
    assistantEnabled: boolean;
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
    {
        icon: Clock,
        value: '24/7',
        label: 'Your shop never closes',
        against: 'only while you are awake',
    },
    {
        icon: Rocket,
        value: '~5 min',
        label: 'From signup to selling',
        against: 'building a bot yourself',
    },
    {
        icon: Zap,
        value: 'Instant',
        label: 'Orders reach your panel',
        against: 'copy and paste, one by one',
    },
    {
        icon: Wallet,
        value: '$0',
        label: 'To start — no card',
        against: 'paying to find out',
    },
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
    {
        q: 'What if I do not have a Meta Business account?',
        a: 'Rent a number from us and start today. It runs on the same official Cloud API, and you can move to your own number whenever your Meta account comes through.',
    },
    {
        q: 'Can I sell from more than one panel?',
        a: 'Yes. Connect as many as you run, and the same bot sells from all of them — each service keeps the panel it came from.',
    },
    {
        q: 'What language does the bot speak?',
        a: 'The order bot speaks English, French, Kiswahili, Turkish and Hindi, chosen per customer rather than per shop — so one bot can serve people who do not share a language. The support menu is in English for now.',
    },
    {
        q: 'Can I build my own thing on top?',
        a: 'Yes. Every shop gets API keys and logs, so you can place orders and read status from your own site or software instead of WhatsApp.',
    },
];

/**
 * The FAQ, restated for search engines.
 *
 * Built from the same FAQS array the page renders, rather than written out a
 * second time: Google drops the rich result when the marked-up answer is not
 * the answer on the page, and two hand-kept copies drift the first time one is
 * edited.
 */
function faqSchema() {
    return JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'FAQPage',
        mainEntity: FAQS.map(({ q, a }) => ({
            '@type': 'Question',
            name: q,
            acceptedAnswer: { '@type': 'Answer', text: a },
        })),
    });
}

export default function Landing({ plans, gateways, demoNumber, assistantEnabled }: Props) {
    const planList = Object.entries(plans);
    const { ziggy } = usePage().props;

    return (
        <>
            {/* Spelled out rather than using <Seo>, which would title this
                "X — Auto Resellers Hub" and read as a subpage. The tags are still
                keyed: Inertia drops any `inertia`-marked default a page does
                not restate, so leaving them off would strip the description
                from the one page most likely to be shared. */}
            <Head title="WhatsApp Bots for Your SMM Panel">
                <meta
                    name="description"
                    content="Sell followers, likes and views on WhatsApp around the clock. Your customers order, pay by mobile money or crypto, and get support automatically — on top of the SMM panel you already run."
                    head-key="description"
                />
                <meta
                    property="og:title"
                    content="Auto Resellers Hub — WhatsApp Bots for SMM Panels"
                    head-key="og:title"
                />
                <meta
                    property="og:description"
                    content="Sell followers, likes and views on WhatsApp around the clock — paid by mobile money or crypto, on top of the panel you already run."
                    head-key="og:description"
                />
                <meta
                    name="twitter:title"
                    content="Auto Resellers Hub — WhatsApp Bots for SMM Panels"
                    head-key="twitter:title"
                />
                <meta
                    name="twitter:description"
                    content="Sell followers, likes and views on WhatsApp around the clock — paid by mobile money or crypto, on top of the panel you already run."
                    head-key="twitter:description"
                />
                {/* Restated for the same reason as the rest: under SSR the
                    keyed tags a page emits are the whole set, and this is the
                    page most likely to be pasted into a WhatsApp group. */}
                <meta
                    property="og:image"
                    content={`${ziggy.url}/logo.png`}
                    head-key="og:image"
                />
                <meta
                    name="twitter:image"
                    content={`${ziggy.url}/logo.png`}
                    head-key="twitter:image"
                />
                {/* The eight questions below, in the form Google reads. Keyed
                    so it replaces rather than joins the site-wide schema in
                    app.blade.php — two ld+json blocks describing different
                    things is how a page ends up with neither. */}
                <script type="application/ld+json" head-key="faq-schema">
                    {faqSchema()}
                </script>
            </Head>

            <LandingNav />

            <main>
                {/* ---- hero ---- */}
                {/* The nav floats rather than occupying a row, so the hero
                    starts under it instead of after it. */}
                <Section className="relative overflow-hidden pt-12 pb-12 sm:pt-16 sm:pb-16 lg:pt-20">
                    {/* Two soft washes behind the fold. Enough to stop the
                        canvas reading as flat white, far short of the neon
                        gradients that make a payments product look like a
                        pump-and-dump. */}
                    <div
                        aria-hidden
                        className="bg-dots pointer-events-none absolute inset-0 -z-10"
                    />
                    <div
                        aria-hidden
                        className="pointer-events-none absolute -top-40 -right-32 -z-10 size-[32rem] rounded-full bg-primary/10 blur-3xl"
                    />
                    <div
                        aria-hidden
                        className="pointer-events-none absolute -bottom-52 -left-40 -z-10 size-[28rem] rounded-full bg-accent-500/10 blur-3xl"
                    />

                    {/* items-start rather than items-center: centring left a
                        band of empty canvas under the shorter column, which is
                        what made the fold read as unfinished. */}
                    <div className="grid items-start gap-10 lg:grid-cols-2 lg:gap-12">
                        {/* Centred on a phone, left-aligned once there are two
                            columns. Ragged left-aligned text in a narrow single
                            column reads as though it has been pushed aside,
                            because there is nothing beside it to explain the
                            asymmetry. */}
                        <div className="text-center lg:pt-6 lg:text-left">
                            {/* The two fears a reseller arrives with — losing
                                the number, and paying to find out. Given the
                                brand colour and a border so they read as
                                assurances rather than decoration. */}
                            <Reveal>
                                <div className="mb-5 flex flex-wrap justify-center gap-2 lg:justify-start">
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

                            {/* Bigger and heavier than the section headings
                                below it. Measured against the reference site,
                                ours started 50px lower and two steps smaller —
                                the first screen is the one that has to work,
                                and it was giving the headline the least room. */}
                            <Reveal delay={80}>
                                <h1 className="font-heading text-[2.4rem] leading-[1.05] font-black tracking-[-0.02em] text-balance sm:text-6xl lg:text-7xl">
                                    WhatsApp bots for your{' '}
                                    <span className="text-gradient relative">
                                        SMM panel
                                        {/* Underlined rather than just coloured:
                                            it marks the phrase as the point of
                                            the sentence, not as decoration. */}
                                        <span
                                            aria-hidden
                                            className="absolute -bottom-1 left-0 h-1 w-full rounded-full bg-primary/30"
                                        />
                                    </span>
                                </h1>
                            </Reveal>

                            {/* Says what the reseller gets, not how it works.
                                The mechanism is the section below; this line
                                has to earn the scroll. */}
                            <Reveal delay={160}>
                                <p className="mx-auto mt-5 max-w-lg text-base text-pretty sm:text-lg text-muted-foreground lg:mx-0">
                                    Sell followers, likes and views around the clock — paid by
                                    mobile money or crypto, without you lifting a finger.
                                </p>
                            </Reveal>

                            <Reveal delay={240}>
                                <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center lg:justify-start">
                                    <Button size="lg" className="w-full shadow-lg shadow-primary/25 sm:w-auto" asChild>
                                        <Link href={route('register')}>
                                            <Rocket className="size-4" />
                                            Start free
                                        </Link>
                                    </Button>
                                    {/* Points at the real bot rather than the price
                                        list: someone who is not sure yet wants to
                                        see it work, not to find out what it costs. */}
                                    <Button size="lg" variant="outline" className="w-full sm:w-auto" asChild>
                                        <Link href={route('try')}>
                                            <PlayCircle className="size-4" />
                                            Try the real bot
                                        </Link>
                                    </Button>
                                </div>

                                {/* The three objections that stop a signup,
                                    answered on one line under the button where
                                    they are read as part of the decision. */}
                                <p className="mt-4 flex flex-wrap items-center justify-center gap-x-4 gap-y-1.5 text-sm text-muted-foreground lg:justify-start">
                                    {[
                                        'Free to start',
                                        'No card required',
                                        'Live in ~5 minutes',
                                    ].map((item) => (
                                        <span key={item} className="flex items-center gap-1.5">
                                            <CheckCircle2 className="size-4 shrink-0 text-primary" />
                                            {item}
                                        </span>
                                    ))}
                                </p>
                            </Reveal>

                            {/* What the bot actually does, under the fold's
                                own column rather than three screens down. The
                                reference site fills this space with a customer
                                count; we do not have one, and the capability
                                list is both true and more use to a reseller
                                deciding whether this fits their shop. */}
                            <Reveal delay={320}>
                                <div className="card-surface mx-auto mt-9 max-w-md rounded-2xl p-5 text-left lg:mx-0">
                                    <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        Your bot handles
                                    </p>

                                    {/* The block is centred but its items stay
                                        left-aligned: a checklist with a ragged
                                        left edge is harder to scan than one
                                        sitting slightly off-centre. */}
                                    <div className="mx-auto mt-3 grid w-fit gap-x-6 gap-y-2 text-left sm:grid-cols-2 lg:mx-0">
                                        {[
                                            'Orders, start to finish',
                                            'Wallet top-ups',
                                            'Refills and order status',
                                            'Support questions',
                                        ].map((item) => (
                                            <p
                                                key={item}
                                                className="flex items-center gap-2 text-sm text-muted-foreground"
                                            >
                                                <CheckCircle2 className="size-4 shrink-0 text-primary" />
                                                {item}
                                            </p>
                                        ))}
                                    </div>
                                </div>
                            </Reveal>
                        </div>

                        <Reveal delay={200}>
                            <PhoneDemo />
                        </Reveal>
                    </div>
                </Section>

                {/* ---- trust bar ---- */}
                <Section muted tight>
                    {/* Cards rather than text floating in a band. As four
                        centred columns the claims had nothing holding them and
                        the padding ran to twice the height of the content, so
                        the whole strip read as a gap between two sections. */}
                    <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                        {TRUST.map((item, index) => (
                            <Reveal
                                key={item.label}
                                delay={index * 80}
                                index={index}
                                card
                                className="h-full"
                            >
                                <div className="card-surface flex h-full flex-col rounded-2xl p-5">
                                    <span className="mb-3 flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15">
                                        <item.icon className="size-4" />
                                    </span>

                                    <p className="font-heading text-xl leading-none font-extrabold text-primary sm:text-2xl">
                                        {item.value}
                                    </p>

                                    <p className="mt-1.5 text-sm font-semibold">{item.label}</p>

                                    {/* The alternative, struck through: naming
                                        what this replaces is the persuasive
                                        half, and the line makes it read as
                                        crossed off rather than as a footnote. */}
                                    <p className="mt-auto flex items-center gap-1.5 pt-3 text-xs text-muted-foreground">
                                        <X className="size-3 shrink-0 text-destructive/60" aria-hidden />
                                        <span className="line-through decoration-destructive/50">
                                            {item.against}
                                        </span>
                                    </p>
                                </div>
                            </Reveal>
                        ))}
                    </div>
                </Section>

                {/* ---- why us ---- */}
                <Section>
                    <SectionHeading
                        eyebrow="Why Auto Resellers Hub"
                        title="Built for resellers, not for end customers"
                        subtitle="During a gold rush, sell shovels. You already have the customers — this is the infrastructure that serves them."
                    />

                    <div className="grid gap-6 md:grid-cols-3">
                        {WHY.map((item, index) => (
                            <Reveal key={item.title} delay={index * 100} index={index}>
                                <Card className="card-surface h-full rounded-2xl py-0 shadow-none">
                                    <CardContent className="p-6 sm:p-7">
                                        <span className="mb-5 flex size-12 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-md shadow-primary/25">
                                            <item.icon className="size-6" />
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

                {/* ---- the two bots ---- */}
                {/* Ahead of the price list on purpose: selling them separately
                    is the pricing model, so a reseller has to be able to tell
                    the two jobs apart before the two prices mean anything. */}
                <Section id="bots" muted>
                    <SectionHeading
                        eyebrow="Two bots, two jobs"
                        title="One sells. One handles what comes after."
                        subtitle="Take either on its own, or both. Most shops start with the order bot and add support once the questions pile up."
                    />

                    <TwoBots />
                </Section>

                {/* ---- what each bot does ---- */}
                <Section id="capabilities">
                    <SectionHeading
                        eyebrow="Everything it does"
                        title="Every command, spelled out"
                        subtitle="No vague promises. This is what your customers can do on WhatsApp, and what lands on your side when they do."
                    />

                    <Capabilities />
                </Section>

                {/* ---- services / pricing ---- */}
                <Section id="pricing">
                    <SectionHeading
                        eyebrow="Pricing"
                        title="Pay for what you use"
                        subtitle="Every service is sold on its own. Take the order bot alone, or the lot."
                    />

                    <PricingTable plans={plans} />
                </Section>

                {/* ---- interactive demo ---- */}
                <Section id="demo" muted>
                    <SectionHeading
                        eyebrow="Try it"
                        title="Press a button. Watch it work."
                        subtitle="This is the real flow your customers get — order, support, wallet. No video, no signup."
                    />

                    <InteractiveDemo />

                    {/* The demo above is a recording of the flow. This is the
                        flow itself, for anyone who wants to type their own. */}
                    <Reveal>
                        <div className="mx-auto mt-10 flex max-w-2xl flex-col items-center gap-4 rounded-2xl border border-primary/25 bg-gradient-to-r from-primary/10 to-primary/5 p-6 text-center sm:flex-row sm:text-left">
                            <div className="flex-1">
                                <p className="font-heading font-bold">Rather type it yourself?</p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Chat with the actual bot on a WhatsApp screen. No signup, nothing is saved.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={route('try')}>
                                    <PlayCircle className="size-4" />
                                    Open the practice chat
                                </Link>
                            </Button>
                        </div>
                    </Reveal>
                </Section>

                {/* ---- how it works ---- */}
                <Section id="how">
                    <SectionHeading
                        eyebrow="How it works"
                        title="Live in about five minutes"
                        subtitle="Four steps between signing up and your first automated order."
                    />

                    <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:gap-5">
                        {STEPS.map((step, index) => (
                            <li key={step.title} className="relative h-full">
                                <Reveal delay={index * 110} index={index} card className="h-full">
                                  <div className="card-surface h-full rounded-2xl p-5 sm:p-6">
                                    <span aria-hidden className="font-heading absolute top-4 right-5 text-4xl font-black text-primary/10">
                                        0{index + 1}
                                    </span>
                                    <span className="mb-4 flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-md shadow-primary/25">
                                        <step.icon className="size-5" />
                                    </span>
                                    <p className="mb-1 text-xs font-semibold text-primary">
                                        Step {index + 1}
                                    </p>
                                    <h3 className="font-heading mb-1 font-bold">{step.title}</h3>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        {step.body}
                                    </p>
                                  </div>
                                </Reveal>
                            </li>
                        ))}
                    </ol>
                </Section>

                {/* ---- panel compatibility ---- */}
                <Section id="panels" muted>
                    <SectionHeading
                        eyebrow="Your panel"
                        title="It sits on top of the panel you already run"
                        subtitle="No migration, no second panel, no engineering. Your panel keeps doing its job — this puts it on WhatsApp."
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

                    <FeatureGrid />
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

                {/* ---- where the money goes ---- */}
                <Section id="money">
                    <SectionHeading
                        eyebrow="Your money"
                        title="Your customers pay you, not us"
                        subtitle="Each customer holds a wallet in your shop, topped up through your own gateway accounts."
                    />

                    <MoneyFlow />

                    <div className="mt-14">
                        <PaymentRail gateways={gateways} />
                    </div>
                </Section>

                {/* ---- faq ---- */}
                <Section id="faq" muted>
                    <SectionHeading
                        eyebrow="FAQ"
                        title="Questions worth asking"
                        subtitle="The ones resellers actually ask before signing up."
                    />

                    <Faq items={FAQS} />
                </Section>

                {/* ---- closing cta ---- */}
                <Section>
                    <Reveal>
                        {/* Filled with the brand green rather than left on the
                            page background: this is the last thing a reader
                            passes, and it should read as an invitation rather
                            than as another paragraph. */}
                        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-primary to-[color-mix(in_oklab,var(--primary)_70%,#0b3b2e)] px-6 py-12 text-center text-primary-foreground shadow-xl shadow-primary/20 sm:px-12 sm:py-16">
                            <div
                                aria-hidden
                                className="pointer-events-none absolute -top-24 -right-20 size-80 rounded-full bg-white/10 blur-3xl"
                            />
                            <div
                                aria-hidden
                                className="pointer-events-none absolute -bottom-28 -left-20 size-80 rounded-full bg-black/10 blur-3xl"
                            />

                            <div className="relative">
                                <h2 className="font-heading text-[1.75rem] font-extrabold text-balance sm:text-4xl">
                                    Put your panel on WhatsApp today
                                </h2>

                                <p className="mx-auto mt-4 max-w-xl text-pretty text-primary-foreground/85">
                                    Free to start, and every service runs in sandbox until you
                                    decide to go live.
                                </p>

                                <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                                    <Button size="lg" variant="secondary" className="w-full sm:w-auto" asChild>
                                        <Link href={route('register')}>
                                            <Rocket className="size-4" />
                                            Create your account
                                        </Link>
                                    </Button>

                                    <Button
                                        size="lg"
                                        variant="outline"
                                        className="w-full border-white/30 bg-transparent sm:w-auto text-primary-foreground hover:bg-white/10 hover:text-primary-foreground"
                                        asChild
                                    >
                                        <Link href={route('try')}>
                                            <PlayCircle className="size-4" />
                                            Try it first
                                        </Link>
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

            {/* cta off: the section directly above is already the closing
                offer, and asking twice in a row reads as nagging. */}
            <PublicFooter demoNumber={demoNumber} cta={false} />

            {/* See PublicLayout: hidden without a key rather than shown
                unable to answer. */}
            {assistantEnabled && <AssistantWidget demoNumber={demoNumber} />}
        </>
    );
}
