import { Button } from '@/components/ui/button';
import { Link, useForm } from '@inertiajs/react';
import {
    Bot,
    CircleCheck,
    CircleMinus,
    FlaskConical,
    Headset,
    Languages,
    Lock,
    Plug,
    TriangleAlert,
} from 'lucide-react';
import { ReactNode } from 'react';
import { Card, Field, inputClass } from './bits';
import { PhoneList } from './PhoneList';
import { BotStatus, Language, Setup } from './types';

/**
 * Bot setup: what is still missing, and how the bot talks.
 *
 * The readiness row leads because it answers the only question that matters
 * before anything else — can this bot actually sell yet? Each check names the
 * thing that is missing and links straight to where it is fixed.
 *
 * The cards below save independently. They were one form once, and saving the
 * spam card would quietly write back whatever the language card happened to be
 * holding; separate posts mean each card owns only its own fields.
 */
export function SetupTab({
    data,
    status,
    languages,
}: {
    data: Setup;
    status: BotStatus;
    languages: Language[];
}) {
    return (
        <div className="space-y-6">
            {data.sandbox && <SandboxBanner />}

            <Card
                title="How the bot sells"
                description="A customer messages your number, picks a service, pays from their balance, and the order goes to your panel — all in chat."
            >
                <div className="grid gap-3 sm:grid-cols-3">
                    <Check
                        ok={data.checks.subscription}
                        icon={Bot}
                        label="Subscription"
                        fixLabel="Get it"
                        href={route('onboarding')}
                    />
                    <Check
                        ok={data.checks.panel}
                        icon={Plug}
                        label="Panel"
                        fixLabel="Connect"
                        href={route('onboarding')}
                    />
                    <Check
                        ok={data.checks.whatsapp}
                        icon={Headset}
                        label="WhatsApp number"
                        fixLabel="Connect"
                        href={route('onboarding')}
                    />
                </div>
            </Card>

            {data.sandbox && (
                <TestNumbersCard numbers={data.testNumbers} botNumber={status.number} />
            )}

            <LanguageAndSupportCard data={data} languages={languages} />
        </div>
    );
}

function SandboxBanner() {
    return (
        <div className="flex flex-wrap items-start gap-3 rounded-xl bg-[oklch(0.77_0.16_70/0.12)] p-4 text-sm">
            <FlaskConical
                className="mt-0.5 size-4 shrink-0 text-[oklch(0.55_0.13_70)]"
                aria-hidden
            />
            <div className="min-w-0 flex-1">
                <p className="font-semibold">Sandbox — not live yet</p>
                <p className="mt-0.5 text-muted-foreground">
                    Set the bot up and try it from your own test numbers. Real customers
                    will not get replies until you go live.
                </p>
            </div>
            <Link
                href={route('onboarding')}
                className="shrink-0 rounded-lg bg-foreground px-3 py-1.5 text-xs font-medium text-background"
            >
                Go live
            </Link>
        </div>
    );
}

function Check({
    ok,
    icon: Icon,
    label,
    fixLabel,
    href,
}: {
    ok: boolean;
    icon: typeof Bot;
    label: string;
    fixLabel: string;
    href: string;
}) {
    return (
        <div className="flex items-center gap-2.5 rounded-xl border border-border p-3">
            {/* The icon repeats what the text says rather than replacing it —
                a tick alone is unreadable in greyscale or to a screen reader. */}
            {ok ? (
                <CircleCheck className="size-4 shrink-0 text-primary" aria-label="Done" />
            ) : (
                <CircleMinus
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-label="Not done"
                />
            )}

            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-1.5 text-sm font-medium">
                    <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                    {label}
                </span>
            </span>

            {!ok && (
                <Link href={href} className="shrink-0 text-xs font-medium text-primary">
                    {fixLabel}
                </Link>
            )}
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
        <Card
            title="Try it first"
            description="While you are in sandbox, only these numbers get replies."
        >
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
                    <p className="rounded-lg bg-primary/10 p-3 text-sm text-primary">
                        Message{' '}
                        <span className="font-data font-semibold">
                            {botNumber ?? 'your bot number'}
                        </span>{' '}
                        from one of those numbers to try the bot.
                    </p>
                )}

                <div className="flex justify-end">
                    <Button type="submit" size="sm" disabled={form.processing}>
                        {form.processing ? 'Saving…' : 'Save test numbers'}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function LanguageAndSupportCard({
    data,
    languages,
}: {
    data: Setup;
    languages: Language[];
}) {
    const form = useForm({
        lang: data.lang,
        groupUrl: data.groupUrl,
        websiteUrl: data.websiteUrl,
        supportMode: data.supportMode,
        staff: data.staff,
    });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('order-bot.setup'), { preserveScroll: true });
            }}
            className="space-y-6"
        >
            <Card
                title="Language & links"
                description="What the bot speaks, and where it points customers."
            >
                <div className="space-y-4">
                    <Field
                        label="Bot language"
                        hint="Used until a customer picks their own"
                        error={form.errors.lang}
                    >
                        <div className="relative">
                            <Languages
                                className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <select
                                value={form.data.lang}
                                onChange={(event) => form.setData('lang', event.target.value)}
                                className={`${inputClass} pl-9`}
                            >
                                {languages.map((language) => (
                                    <option key={language.code} value={language.code}>
                                        {language.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Group link" error={form.errors.groupUrl}>
                            <input
                                type="url"
                                value={form.data.groupUrl}
                                onChange={(event) =>
                                    form.setData('groupUrl', event.target.value)
                                }
                                placeholder="https://chat.whatsapp.com/…"
                                className={inputClass}
                            />
                        </Field>

                        <Field label="Website" error={form.errors.websiteUrl}>
                            <input
                                type="url"
                                value={form.data.websiteUrl}
                                onChange={(event) =>
                                    form.setData('websiteUrl', event.target.value)
                                }
                                placeholder="https://…"
                                className={inputClass}
                            />
                        </Field>
                    </div>
                </div>
            </Card>

            <Card
                title="When a customer needs help"
                description="The order bot sells. This is what happens when someone asks it something it cannot answer."
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <SupportOption
                        selected={form.data.supportMode === 'admin'}
                        onSelect={() => form.setData('supportMode', 'admin')}
                        icon={Headset}
                        title="Send it to you"
                        description="The bot hands the conversation to your staff numbers below."
                    />

                    <SupportOption
                        selected={form.data.supportMode === 'ai'}
                        onSelect={() => form.setData('supportMode', 'ai')}
                        icon={Bot}
                        title="AI answers"
                        badge="$5/mo"
                        description="An AI add-on replies for you, using your own catalogue and rules."
                    >
                        {form.data.supportMode === 'ai' && <AiState ai={data.ai} />}
                    </SupportOption>
                </div>

                <div className="mt-4">
                    <p className="text-sm font-medium">Staff numbers</p>
                    <p className="mb-2 text-xs text-muted-foreground">
                        These get handed the conversation, and are never blocked by
                        anti-spam.
                    </p>
                    <PhoneList
                        numbers={form.data.staff}
                        onChange={(next) => form.setData('staff', next)}
                        empty="No staff numbers yet."
                    />
                </div>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save changes'}
                </Button>
            </div>
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
                {ai.answersToday} answered today · {ai.answersTotal} in total.
                Your DeepSeek key is billed for each one.
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
