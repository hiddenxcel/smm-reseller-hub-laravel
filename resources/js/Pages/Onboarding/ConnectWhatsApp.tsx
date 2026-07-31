import OnboardingLayout, { WizardStep } from '@/Layouts/OnboardingLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertCircle, Check, Copy, Loader2, Sparkles, Trash2, Wrench } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type ConnectedNumber = {
    id: number;
    phone_number_id: string;
    display_number: string | null;
    bot_type: string;
    source: string;
};

type RentableNumber = {
    id: number;
    display_number: string;
    country: string | null;
    country_code: string | null;
    currency: string;
    price: number;
};

type Rental = {
    id: number;
    display_number: string | null;
    country: string | null;
    startedAt: string | null;
};

type Props = {
    step: string;
    steps: WizardStep[];
    completed: number;
    webhookUrl: string;
    verifyToken: string;
    numbers: ConnectedNumber[];
    rentable: RentableNumber[];
    rentals: Rental[];
};

/**
 * One number, one bot. A shared number meant the router had to guess which
 * bot an ambiguous message was for — and guess wrong often enough to pull a
 * customer out of a half-finished order. Running both services means two
 * numbers, which is why renting a second one is a few clicks.
 */
const BOT_OPTIONS = [
    { value: 'order', label: 'Take orders', hint: 'Sells your services and collects payment.' },
    { value: 'support', label: 'Handle support', hint: 'Answers questions and raises tickets.' },
];

export default function ConnectWhatsApp({
    step,
    steps,
    completed,
    webhookUrl,
    verifyToken,
    numbers,
    rentable,
    rentals,
}: Props) {
    // Renting is the default because setting up a Meta app is where most
    // resellers stall — but bringing your own number stays a peer, not a
    // footnote, for anyone who already has one.
    const [mode, setMode] = useState<'rent' | 'own'>(
        numbers.some((number) => number.source === 'own') ? 'own' : 'rent',
    );

    return (
        <OnboardingLayout step={step} steps={steps} completed={completed}>
            <Head title="Connect WhatsApp" />

            <div className="max-w-xl">
                <h1 className="font-heading text-2xl font-extrabold">Connect WhatsApp</h1>
                <p className="mt-2 text-muted-foreground">
                    Your bot needs a WhatsApp number to answer on. Rent one from us and it
                    works immediately, or connect your own from Meta&rsquo;s Cloud API.
                </p>

                {numbers.length > 0 && <ConnectedList numbers={numbers} rentals={rentals} />}

                <div className="mt-8 grid gap-2 sm:grid-cols-2">
                    <ModeCard
                        active={mode === 'rent'}
                        onClick={() => setMode('rent')}
                        icon={Sparkles}
                        title="Rent a number"
                        hint="Ready in seconds. No Meta setup."
                    />
                    <ModeCard
                        active={mode === 'own'}
                        onClick={() => setMode('own')}
                        icon={Wrench}
                        title="Use my own"
                        hint="You already have a Cloud API number."
                    />
                </div>

                {mode === 'rent' ? (
                    <RentPanel rentable={rentable} />
                ) : (
                    <OwnNumberPanel webhookUrl={webhookUrl} verifyToken={verifyToken} />
                )}
            </div>
        </OnboardingLayout>
    );
}

function ConnectedList({ numbers, rentals }: { numbers: ConnectedNumber[]; rentals: Rental[] }) {
    const release = (rental: Rental) => {
        router.delete(route('onboarding.whatsapp.release', rental.id), {
            preserveScroll: true,
        });
    };

    return (
        <div className="mt-6 rounded-xl border border-border bg-muted/40 p-4">
            <p className="mb-2 text-sm font-semibold">Already connected</p>
            <ul className="space-y-2 text-sm">
                {numbers.map((number) => {
                    // A rented number can be handed back; your own is yours to
                    // manage in Meta, so there is nothing for us to release.
                    const rental = rentals.find(
                        (row) => row.display_number === number.display_number,
                    );

                    return (
                        <li key={number.id} className="flex items-center gap-2">
                            <Check className="size-4 shrink-0 text-primary" />
                            <span className="min-w-0 flex-1 truncate text-muted-foreground">
                                {number.display_number ?? number.phone_number_id}
                                <span className="ml-1.5 text-xs">({number.bot_type})</span>
                                {number.source === 'rented' && (
                                    <span className="ml-1.5 text-xs">· rented</span>
                                )}
                            </span>
                            {rental && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => release(rental)}
                                    aria-label={`Release ${number.display_number}`}
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function RentPanel({ rentable }: { rentable: RentableNumber[] }) {
    const { data, setData, post, processing, errors } = useForm({
        platform_number_id: rentable[0]?.id ?? 0,
        bot_type: 'order',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('onboarding.whatsapp.rent'));
    };

    if (rentable.length === 0) {
        return (
            <div className="mt-6 rounded-xl border border-dashed border-border bg-muted/40 p-6">
                <h2 className="font-heading font-bold">No numbers available right now</h2>
                <p className="mt-1.5 text-sm text-muted-foreground">
                    Every number is currently rented out. Connect your own for now, or check
                    back shortly.
                </p>
            </div>
        );
    }

    const chosen = rentable.find((number) => number.id === data.platform_number_id);

    return (
        <form onSubmit={submit} className="mt-6 space-y-6">
            <div>
                <p className="text-sm font-medium">Pick a number</p>
                <p className="mt-1 text-xs text-muted-foreground">
                    Paid once. It stays yours until you hand it back.
                </p>

                <div className="mt-3 space-y-2">
                    {rentable.map((number) => (
                        <label
                            key={number.id}
                            className={[
                                'flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm transition-colors',
                                number.id === data.platform_number_id
                                    ? 'border-primary bg-accent/50'
                                    : 'border-border hover:border-primary/40',
                            ].join(' ')}
                        >
                            <input
                                type="radio"
                                name="platform_number_id"
                                value={number.id}
                                checked={number.id === data.platform_number_id}
                                onChange={() => setData('platform_number_id', number.id)}
                                className="size-4 accent-primary"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium [font-variant-numeric:tabular-nums]">
                                    {number.display_number}
                                </span>
                                {number.country && (
                                    <span className="text-xs text-muted-foreground">
                                        {number.country}
                                    </span>
                                )}
                            </span>
                            <span className="shrink-0 text-sm font-semibold [font-variant-numeric:tabular-nums]">
                                {number.price === 0
                                    ? 'Free'
                                    : `${number.currency} ${number.price.toFixed(2)}`}
                            </span>
                        </label>
                    ))}
                </div>

                <FieldError message={errors.platform_number_id} />
            </div>

            <BotTypeChoice
                name="rent_bot_type"
                value={data.bot_type}
                onChange={(value) => setData('bot_type', value)}
                error={errors.bot_type}
            />

            <Button type="submit" size="lg" disabled={processing} className="w-full">
                {processing && <Loader2 className="size-4 animate-spin" />}
                {chosen && chosen.price > 0
                    ? `Rent for ${chosen.currency} ${chosen.price.toFixed(2)}`
                    : 'Rent this number'}
            </Button>

            <p className="text-xs text-muted-foreground">
                We keep the Meta app and the access token — you never have to touch either.
            </p>
        </form>
    );
}

function OwnNumberPanel({
    webhookUrl,
    verifyToken,
}: {
    webhookUrl: string;
    verifyToken: string;
}) {
    const { data, setData, post, processing, errors } = useForm({
        phone_number_id: '',
        token: '',
        waba_id: '',
        display_number: '',
        bot_type: 'order',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('onboarding.whatsapp.store'));
    };

    return (
        <div>
            <p className="mt-6 text-sm text-muted-foreground">
                Because this is the official API, your number is not at risk the way
                QR-scanning tools are.
            </p>

            {/* Meta needs these two before it will deliver anything. */}
            <section className="mt-6 rounded-xl border border-border p-5">
                <h2 className="font-heading font-bold">First, tell Meta where to send messages</h2>
                <p className="mt-1.5 text-sm text-muted-foreground">
                    In your Meta app, under WhatsApp &rsaquo; Configuration, paste these into
                    the webhook settings and subscribe to <strong>messages</strong>.
                </p>

                <div className="mt-4 space-y-3">
                    <CopyField label="Callback URL" value={webhookUrl} />
                    <CopyField
                        label="Verify token"
                        value={verifyToken}
                        emptyHint="Not configured yet — ask your administrator to set META_VERIFY_TOKEN."
                    />
                </div>
            </section>

            <form onSubmit={submit} className="mt-8 space-y-5">
                <h2 className="font-heading font-bold">Then add your number here</h2>

                <div>
                    <Label htmlFor="phone_number_id">Phone number ID</Label>
                    <Input
                        id="phone_number_id"
                        value={data.phone_number_id}
                        onChange={(event) => setData('phone_number_id', event.target.value)}
                        className="mt-1.5"
                        required
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        From the same Meta screen — this is how we know a message is yours.
                    </p>
                    <FieldError message={errors.phone_number_id} />
                </div>

                <div>
                    <Label htmlFor="token">Access token</Label>
                    <Input
                        id="token"
                        type="password"
                        value={data.token}
                        onChange={(event) => setData('token', event.target.value)}
                        className="mt-1.5"
                        required
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        A permanent token. Encrypted before we store it.
                    </p>
                    <FieldError message={errors.token} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="display_number">Phone number (optional)</Label>
                        <Input
                            id="display_number"
                            value={data.display_number}
                            onChange={(event) => setData('display_number', event.target.value)}
                            placeholder="255700000000"
                            className="mt-1.5"
                        />
                        <FieldError message={errors.display_number} />
                    </div>

                    <div>
                        <Label htmlFor="waba_id">WABA ID (optional)</Label>
                        <Input
                            id="waba_id"
                            value={data.waba_id}
                            onChange={(event) => setData('waba_id', event.target.value)}
                            className="mt-1.5"
                        />
                        <FieldError message={errors.waba_id} />
                    </div>
                </div>

                <BotTypeChoice
                    name="bot_type"
                    value={data.bot_type}
                    onChange={(value) => setData('bot_type', value)}
                    error={errors.bot_type}
                />

                <Button type="submit" size="lg" disabled={processing} className="w-full">
                    {processing && <Loader2 className="size-4 animate-spin" />}
                    Connect number
                </Button>
            </form>
        </div>
    );
}

/**
 * Which bot this number answers as. Exactly one — the number is how an
 * inbound message finds its bot, so it cannot mean two things at once.
 */
function BotTypeChoice({
    name,
    value,
    onChange,
    error,
}: {
    name: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <fieldset>
            <legend className="text-sm font-medium">Which bot answers on this number?</legend>
            <p className="mt-1 text-xs text-muted-foreground">
                One number runs one bot. To run both, add a second number.
            </p>

            <div className="mt-3 space-y-2">
                {BOT_OPTIONS.map((option) => (
                    <label
                        key={option.value}
                        className={[
                            'flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 text-sm transition-colors',
                            value === option.value
                                ? 'border-primary bg-accent/50'
                                : 'border-border hover:border-primary/40',
                        ].join(' ')}
                    >
                        <input
                            type="radio"
                            name={name}
                            value={option.value}
                            checked={value === option.value}
                            onChange={(event) => onChange(event.target.value)}
                            className="mt-0.5 size-4 accent-primary"
                        />
                        <span className="min-w-0">
                            <span className="block font-medium">{option.label}</span>
                            <span className="block text-xs text-muted-foreground">
                                {option.hint}
                            </span>
                        </span>
                    </label>
                ))}
            </div>

            <FieldError message={error} />
        </fieldset>
    );
}

function ModeCard({
    active,
    onClick,
    icon: Icon,
    title,
    hint,
}: {
    active: boolean;
    onClick: () => void;
    icon: typeof Sparkles;
    title: string;
    hint: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={[
                'flex items-start gap-2.5 rounded-xl border p-3.5 text-left transition-colors',
                active ? 'border-primary bg-accent/50' : 'border-border hover:border-primary/40',
            ].join(' ')}
        >
            <Icon className={`mt-0.5 size-4 shrink-0 ${active ? 'text-primary' : 'text-muted-foreground'}`} />
            <span className="min-w-0">
                <span className="block text-sm font-semibold">{title}</span>
                <span className="block text-xs text-muted-foreground">{hint}</span>
            </span>
        </button>
    );
}

function CopyField({
    label,
    value,
    emptyHint,
}: {
    label: string;
    value: string;
    emptyHint?: string;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(value);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    if (! value && emptyHint) {
        return (
            <div>
                <p className="mb-1 text-xs font-medium text-muted-foreground">{label}</p>
                <p className="text-sm text-destructive">{emptyHint}</p>
            </div>
        );
    }

    return (
        <div>
            <p className="mb-1 text-xs font-medium text-muted-foreground">{label}</p>
            <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded-md bg-muted px-3 py-2 text-xs">
                    {value}
                </code>
                <Button type="button" variant="outline" size="sm" onClick={copy}>
                    {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>
        </div>
    );
}

function FieldError({ message }: { message?: string }) {
    if (! message) {
        return null;
    }

    return (
        <p className="mt-2 flex items-start gap-1.5 text-sm text-destructive">
            <AlertCircle className="mt-0.5 size-4 shrink-0" />
            <span>{message}</span>
        </p>
    );
}
