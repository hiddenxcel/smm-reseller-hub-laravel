import OnboardingLayout, { WizardStep } from '@/Layouts/OnboardingLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/react';
import { AlertCircle, Check, Copy, Loader2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type ConnectedNumber = {
    id: number;
    phone_number_id: string;
    display_number: string | null;
    bot_type: string;
};

type Props = {
    step: string;
    steps: WizardStep[];
    completed: number;
    webhookUrl: string;
    verifyToken: string;
    numbers: ConnectedNumber[];
};

export default function ConnectWhatsApp({
    step,
    steps,
    completed,
    webhookUrl,
    verifyToken,
    numbers,
}: Props) {
    const { data, setData, post, processing, errors } = useForm({
        phone_number_id: '',
        token: '',
        waba_id: '',
        display_number: '',
        bot_type: 'both',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('onboarding.whatsapp.store'));
    };

    return (
        <OnboardingLayout step={step} steps={steps} completed={completed}>
            <Head title="Connect WhatsApp" />

            <div className="max-w-xl">
                <h1 className="font-heading text-2xl font-extrabold">Connect WhatsApp</h1>
                <p className="mt-2 text-muted-foreground">
                    Use your own number from Meta&rsquo;s Cloud API. Because it is the official
                    API, your number is not at risk the way QR-scanning tools are.
                </p>

                {numbers.length > 0 && (
                    <div className="mt-6 rounded-xl border border-border bg-muted/40 p-4">
                        <p className="mb-2 text-sm font-semibold">Already connected</p>
                        <ul className="space-y-1 text-sm text-muted-foreground">
                            {numbers.map((number) => (
                                <li key={number.id} className="flex items-center gap-2">
                                    <Check className="size-4 text-primary" />
                                    {number.display_number ?? number.phone_number_id}
                                    <span className="text-xs">({number.bot_type})</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Meta needs these two before it will deliver anything. */}
                <section className="mt-8 rounded-xl border border-border p-5">
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

                    <fieldset>
                        <legend className="text-sm font-medium">What should this number do?</legend>

                        <div className="mt-2 space-y-2">
                            {[
                                { value: 'both', label: 'Take orders and handle support' },
                                { value: 'order', label: 'Take orders only' },
                                { value: 'support', label: 'Handle support only' },
                            ].map((option) => (
                                <label
                                    key={option.value}
                                    className="flex cursor-pointer items-center gap-2.5 text-sm"
                                >
                                    <input
                                        type="radio"
                                        name="bot_type"
                                        value={option.value}
                                        checked={data.bot_type === option.value}
                                        onChange={(event) => setData('bot_type', event.target.value)}
                                        className="size-4 accent-primary"
                                    />
                                    {option.label}
                                </label>
                            ))}
                        </div>

                        <FieldError message={errors.bot_type} />
                    </fieldset>

                    <Button type="submit" size="lg" disabled={processing} className="w-full">
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        Connect number
                    </Button>
                </form>
            </div>
        </OnboardingLayout>
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
