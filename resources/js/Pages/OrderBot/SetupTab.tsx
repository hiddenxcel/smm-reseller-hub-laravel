import { Button } from '@/components/ui/button';
import { Link, useForm } from '@inertiajs/react';
import {
    Bot,
    CircleCheck,
    CircleMinus,
    Copy,
    ExternalLink,
    FlaskConical,
    Headset,
    Lock,
    TriangleAlert,
} from 'lucide-react';
import { ReactNode, useState } from 'react';
import { Card, Field, SaveBar, inputClass } from './bits';
import { PhoneList } from './PhoneList';
import { BotStatus, Setup } from './types';

/**
 * Bot setup: what is still missing, and how customers reach and get help from
 * the bot.
 *
 * The first card leads because it answers the only questions that matter
 * before anything else — can this bot sell yet, and how does a customer reach
 * it? Whatever is missing is named and links to where it is fixed.
 *
 * Language lives under Settings, with the rest of the shop's defaults, rather
 * than being asked twice. The two forms save independently: they were one form
 * once, and saving one card would quietly write back whatever another happened
 * to be holding.
 */
export function SetupTab({ data, status }: { data: Setup; status: BotStatus }) {
    return (
        <div className="space-y-4 sm:space-y-6">
            {data.sandbox && <SandboxBanner />}

            <BotCard data={data} status={status} />

            {data.sandbox && (
                <TestNumbersCard numbers={data.testNumbers} botNumber={status.number} />
            )}

            <HelpForm data={data} />
        </div>
    );
}

/** Where the bot lives, and whether it is ready to sell. */
function BotCard({ data, status }: { data: Setup; status: BotStatus }) {
    const [copied, setCopied] = useState(false);

    // wa.me wants digits only: "+255 712 345 678" becomes 255712345678.
    const digits = (status.number ?? '').replace(/\D/g, '');
    const link = digits === '' ? null : `https://wa.me/${digits}`;

    const missing = [
        !data.checks.subscription && { label: 'Subscription', fix: 'Get it', href: route('onboarding') },
        !data.checks.panel && { label: 'Panel', fix: 'Connect', href: route('onboarding') },
        !data.checks.whatsapp && { label: 'WhatsApp number', fix: 'Connect', href: route('order-bot', 'number') },
    ].filter(Boolean) as Array<{ label: string; fix: string; href: string }>;

    return (
        <Card title="Your bot">
            {link ? (
                <div className="space-y-3">
                    <div className="flex items-center gap-2 rounded-xl border border-border bg-muted/40 px-3 py-2.5">
                        <code className="font-data min-w-0 flex-1 truncate text-sm">{link}</code>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                navigator.clipboard?.writeText(link);
                                setCopied(true);
                                setTimeout(() => setCopied(false), 1500);
                            }}
                            aria-label="Copy the bot's WhatsApp link"
                        >
                            {copied ? 'Copied' : <Copy className="size-3.5" />}
                        </Button>
                    </div>

                    <div className="grid gap-2 sm:grid-cols-2">
                        <Button asChild>
                            <a href={link} target="_blank" rel="noreferrer">
                                <ExternalLink className="size-4" />
                                Open in WhatsApp
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={route('onboarding.step', 'test')}>Practice chat</Link>
                        </Button>
                    </div>

                    <p className="text-xs text-muted-foreground">
                        Share this link — a customer taps it and lands in a chat with your bot.
                    </p>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Connect a WhatsApp number and your bot&rsquo;s link appears here.
                </p>
            )}

            {/* Only what is missing: three green ticks would say nothing a
                glance at "Online" has not already said. */}
            {missing.length > 0 ? (
                <ul className="mt-4 space-y-2 border-t border-border pt-4">
                    {missing.map((item) => (
                        <li
                            key={item.label}
                            className="flex items-center justify-between gap-3 text-sm"
                        >
                            <span className="flex items-center gap-2">
                                <CircleMinus
                                    className="size-4 shrink-0 text-muted-foreground"
                                    aria-label="Not done"
                                />
                                {item.label}
                            </span>
                            <Link
                                href={item.href}
                                className="shrink-0 text-xs font-semibold text-primary"
                            >
                                {item.fix}
                            </Link>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mt-4 flex items-center gap-2 border-t border-border pt-4 text-sm text-muted-foreground">
                    <CircleCheck className="size-4 shrink-0 text-primary" aria-hidden />
                    Subscription, panel and number are all in place.
                </p>
            )}
        </Card>
    );
}

function SandboxBanner() {
    return (
        <div className="flex flex-wrap items-center gap-3 rounded-2xl bg-[oklch(0.77_0.16_70/0.12)] p-4 text-sm">
            <FlaskConical
                className="size-4 shrink-0 text-[oklch(0.55_0.13_70)]"
                aria-hidden
            />
            <p className="min-w-0 flex-1">
                <span className="font-semibold">Test mode.</span>{' '}
                <span className="text-muted-foreground">
                    Only your test numbers get replies until you go live.
                </span>
            </p>
            <Link
                href={route('onboarding')}
                className="shrink-0 rounded-lg bg-foreground px-3 py-1.5 text-xs font-medium text-background"
            >
                Go live
            </Link>
        </div>
    );
}

function TestNumbersCard({
    numbers,
    botNumber,
}: {
    numbers: string[];
    botNumber: string | null;
}) {
    const form = useForm({ testNumbers: numbers });

    return (
        <Card title="Test numbers" description="In test mode, only these numbers get replies.">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('order-bot.test-numbers'), { preserveScroll: true });
                }}
                className="space-y-4"
            >
                <PhoneList
                    numbers={form.data.testNumbers}
                    onChange={(next) => form.setData('testNumbers', next)}
                    empty="No test numbers yet."
                />

                {form.data.testNumbers.length > 0 && (
                    <p className="rounded-xl bg-primary/10 p-3 text-sm text-primary">
                        Message{' '}
                        <span className="font-data font-semibold">
                            {botNumber ?? 'your bot number'}
                        </span>{' '}
                        from one of them to try the bot.
                    </p>
                )}

                <Button type="submit" className="w-full sm:w-auto" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save test numbers'}
                </Button>
            </form>
        </Card>
    );
}

/** Links to share, and who handles a question the bot cannot answer. */
function HelpForm({ data }: { data: Setup }) {
    // `lang` rides along unchanged: it is edited under Settings, but this
    // endpoint saves it with the rest, so it must be sent back as it was.
    const form = useForm({
        lang: data.lang,
        groupUrl: data.groupUrl,
        websiteUrl: data.websiteUrl,
        supportMode: data.supportMode,
        autoRefund: data.autoRefund,
        staff: data.staff,
    });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('order-bot.setup'), { preserveScroll: true });
            }}
            className="space-y-4 sm:space-y-6"
        >
            <Card title="Links" description="Where the bot points customers. Both are optional.">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Group link" error={form.errors.groupUrl}>
                        <input
                            type="url"
                            value={form.data.groupUrl}
                            onChange={(event) => form.setData('groupUrl', event.target.value)}
                            placeholder="https://chat.whatsapp.com/…"
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Website" error={form.errors.websiteUrl}>
                        <input
                            type="url"
                            value={form.data.websiteUrl}
                            onChange={(event) => form.setData('websiteUrl', event.target.value)}
                            placeholder="https://…"
                            className={inputClass}
                        />
                    </Field>
                </div>
            </Card>

            <Card
                title="Automatic refunds"
                description="What happens to a customer's money when the provider cannot deliver."
            >
                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        checked={form.data.autoRefund}
                        onChange={(event) => form.setData('autoRefund', event.target.checked)}
                        className="mt-1 size-4 accent-primary"
                    />
                    <span>
                        <span className="block text-sm font-medium">
                            Refund customers automatically
                        </span>
                        <span className="mt-1 block text-xs text-muted-foreground">
                            If the provider cancels an order, the customer gets the full amount
                            back in their wallet. If it delivers only part, they get back the
                            share that was not delivered. They are told on WhatsApp. An order the
                            provider never received is not refunded: it stays failed for you to
                            resend, and the customer still sees it as pending.
                        </span>
                    </span>
                </label>
            </Card>

            <Card
                title="When a customer needs help"
                description="What happens when someone asks something the bot cannot answer."
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <SupportOption
                        selected={form.data.supportMode === 'admin'}
                        onSelect={() => form.setData('supportMode', 'admin')}
                        icon={Headset}
                        title="Send it to you"
                        description="Your staff numbers get the conversation."
                    />

                    <SupportOption
                        selected={form.data.supportMode === 'ai'}
                        onSelect={() => form.setData('supportMode', 'ai')}
                        icon={Bot}
                        title="AI answers"
                        badge="$5/mo"
                        description="An AI add-on replies from your catalogue and rules."
                    >
                        {form.data.supportMode === 'ai' && <AiState ai={data.ai} />}
                    </SupportOption>
                </div>

                <div className="mt-5">
                    <p className="text-sm font-medium">Staff numbers</p>
                    <p className="mb-2 text-xs text-muted-foreground">
                        They get handed the conversation and are never blocked by anti-spam.
                    </p>
                    <PhoneList
                        numbers={form.data.staff}
                        onChange={(next) => form.setData('staff', next)}
                        empty="No staff numbers yet."
                    />
                </div>
            </Card>

            <SaveBar processing={form.processing} dirty={form.isDirty} />
        </form>
    );
}

/** AI is two separate things — a subscription and a key — and it needs both. */
function AiState({
    ai,
}: {
    ai: { active: boolean; hasKey: boolean; answersToday: number; answersTotal: number };
}) {
    if (!ai.active) {
        return (
            <Note
                icon={Lock}
                tone="locked"
                text="The AI add-on is not active."
                action={{ label: 'Subscribe', href: route('onboarding') }}
            />
        );
    }

    if (!ai.hasKey) {
        return (
            <Note
                icon={TriangleAlert}
                tone="warn"
                text="No API key saved, so the AI cannot reply yet."
                action={{ label: 'Add key', href: route('onboarding') }}
            />
        );
    }

    return (
        <div className="space-y-2">
            <Note icon={CircleCheck} tone="ok" text="AI support is ready." />

            {/* DeepSeek bills the reseller directly for every answer, and we
                never see that invoice — this is the only place they can see
                what their bot has been doing on their key. */}
            <p className="text-xs text-muted-foreground">
                {ai.answersToday} answered today · {ai.answersTotal} in total. Your DeepSeek key is
                billed for each one.
            </p>
        </div>
    );
}

function Note({
    icon: Icon,
    tone,
    text,
    action,
}: {
    icon: typeof Lock;
    tone: 'ok' | 'warn' | 'locked';
    text: string;
    action?: { label: string; href: string };
}) {
    const tones = {
        ok: 'bg-primary/10 text-primary',
        warn: 'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]',
        locked: 'bg-destructive/10 text-destructive',
    };

    return (
        <div
            className={`mt-3 flex flex-wrap items-center gap-2 rounded-lg p-2.5 text-xs ${tones[tone]}`}
        >
            <Icon className="size-3.5 shrink-0" aria-hidden />
            <span className="min-w-0 flex-1">{text}</span>
            {action && (
                <Link href={action.href} className="shrink-0 font-semibold underline">
                    {action.label}
                </Link>
            )}
        </div>
    );
}

function SupportOption({
    selected,
    onSelect,
    icon: Icon,
    title,
    description,
    badge,
    children,
}: {
    selected: boolean;
    onSelect: () => void;
    icon: typeof Bot;
    title: string;
    description: string;
    badge?: string;
    children?: ReactNode;
}) {
    return (
        <label
            className={[
                'block cursor-pointer rounded-xl border p-4 transition-colors',
                selected ? 'border-primary bg-primary/5' : 'border-border hover:bg-accent',
            ].join(' ')}
        >
            <span className="flex items-center gap-2.5">
                <input
                    type="radio"
                    name="supportMode"
                    checked={selected}
                    onChange={onSelect}
                    className="size-4 shrink-0 border-input text-primary focus:ring-ring"
                />
                <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                <span className="flex-1 text-sm font-semibold">{title}</span>
                {badge && (
                    <span className="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-bold text-primary">
                        {badge}
                    </span>
                )}
            </span>

            <span className="mt-2 block text-sm text-muted-foreground">{description}</span>

            {children}
        </label>
    );
}
