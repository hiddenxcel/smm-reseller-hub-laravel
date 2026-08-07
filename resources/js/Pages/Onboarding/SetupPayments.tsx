import OnboardingLayout, { WizardStep } from '@/Layouts/OnboardingLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertCircle, Check, Clock, Loader2, Trash2 } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

type GatewayField = {
    name: string;
    label: string;
};

type GatewayOption = {
    code: string;
    label: string;
    type: string;
    ready: boolean;
    fields: GatewayField[];
};

type ConnectedGateway = {
    code: string;
    label: string;
    ready: boolean;
};

type Props = {
    step: string;
    steps: WizardStep[];
    completed: number;
    gateways: GatewayOption[];
    connected: ConnectedGateway[];
    canSkip: boolean;
};

const TYPE_LABELS: Record<string, string> = {
    mobile: 'Mobile money',
    crypto: 'Crypto',
    card: 'Cards',
};

export default function SetupPayments({ step, steps, completed, gateways, connected, canSkip }: Props) {
    // Ready gateways first: a reseller picking from the top gets one that
    // actually takes money today.
    const ordered = useMemo(
        () => [...gateways].sort((a, b) => Number(b.ready) - Number(a.ready)),
        [gateways],
    );

    const [selected, setSelected] = useState(ordered[0]?.code ?? '');
    const gateway = ordered.find((option) => option.code === selected);

    const isConnected = connected.some((row) => row.code === selected);

    const { data, setData, post, processing, errors, reset } = useForm<{
        gateway: string;
        credentials: Record<string, string>;
    }>({
        gateway: selected,
        credentials: {},
    });

    const choose = (code: string) => {
        setSelected(code);
        setData({ gateway: code, credentials: {} });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('onboarding.payments.store'), {
            onSuccess: () => reset('credentials'),
        });
    };

    const disconnect = (code: string) => {
        router.delete(route('onboarding.payments.destroy', code), { preserveScroll: true });
    };

    return (
        <OnboardingLayout step={step} steps={steps} completed={completed} canSkip={canSkip}>
            <Head title="Set up payments" />

            <div className="max-w-xl">
                <h1 className="font-heading text-2xl font-extrabold">Set up payments</h1>
                <p className="mt-2 text-muted-foreground">
                    Add a gateway so your customers can top up their wallets themselves,
                    without waiting on you to confirm anything.
                </p>

                {connected.length > 0 && (
                    <div className="mt-6 rounded-xl border border-border bg-muted/40 p-4">
                        <p className="mb-2 text-sm font-semibold">Connected</p>
                        <ul className="space-y-1.5 text-sm">
                            {connected.map((row) => (
                                <li key={row.code} className="flex items-center gap-2">
                                    <Check className="size-4 shrink-0 text-primary" />
                                    <span className="min-w-0 flex-1 truncate">{row.label}</span>
                                    {! row.ready && (
                                        <span className="text-xs text-muted-foreground">
                                            not live yet
                                        </span>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => disconnect(row.code)}
                                        aria-label={`Disconnect ${row.label}`}
                                    >
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <form onSubmit={submit} className="mt-8 space-y-6">
                    <fieldset>
                        <legend className="text-sm font-medium">Choose a gateway</legend>

                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                            {ordered.map((option) => (
                                <label
                                    key={option.code}
                                    className={[
                                        'flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 text-sm transition-colors',
                                        option.code === selected
                                            ? 'border-primary bg-accent/50'
                                            : 'border-border hover:border-primary/40',
                                    ].join(' ')}
                                >
                                    <input
                                        type="radio"
                                        name="gateway"
                                        value={option.code}
                                        checked={option.code === selected}
                                        onChange={() => choose(option.code)}
                                        className="mt-0.5 size-4 accent-primary"
                                    />
                                    <span className="min-w-0">
                                        <span className="block font-medium">{option.label}</span>
                                        <span className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                            {TYPE_LABELS[option.type] ?? option.type}
                                            {! option.ready && (
                                                <>
                                                    <Clock className="size-3" />
                                                    coming soon
                                                </>
                                            )}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>

                        <FieldError message={errors.gateway} />
                    </fieldset>

                    {gateway && (
                        <div className="space-y-5">
                            {! gateway.ready && (
                                <p className="flex items-start gap-2 rounded-lg border border-dashed border-border bg-muted/40 p-3 text-sm text-muted-foreground">
                                    <Clock className="mt-0.5 size-4 shrink-0" />
                                    <span>
                                        You can store {gateway.label} keys now, but this gateway is
                                        not wired up yet &mdash; it will not take payments until it
                                        is, and it does not count towards finishing this step.
                                    </span>
                                </p>
                            )}

                            {gateway.fields.map((field) => (
                                <div key={field.name}>
                                    <Label htmlFor={field.name}>{field.label}</Label>
                                    <Input
                                        id={field.name}
                                        type="password"
                                        autoComplete="off"
                                        value={data.credentials[field.name] ?? ''}
                                        onChange={(event) =>
                                            setData('credentials', {
                                                ...data.credentials,
                                                [field.name]: event.target.value,
                                            })
                                        }
                                        placeholder={isConnected ? 'Leave blank to keep current' : ''}
                                        className="mt-1.5"
                                    />
                                    <FieldError message={errors[`credentials.${field.name}`]} />
                                </div>
                            ))}

                            <p className="text-xs text-muted-foreground">
                                Keys are encrypted before we store them, and are never shown again
                                once saved.
                            </p>

                            <Button type="submit" size="lg" disabled={processing} className="w-full">
                                {processing && <Loader2 className="size-4 animate-spin" />}
                                {isConnected ? 'Update credentials' : `Connect ${gateway.label}`}
                            </Button>
                        </div>
                    )}
                </form>
            </div>
        </OnboardingLayout>
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
