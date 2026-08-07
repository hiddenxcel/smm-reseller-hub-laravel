import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { Check, Clock, Loader2, Trash2 } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';
import { Card, FieldError, NeedsAttention } from './bits';
import { ConnectedGateway, GatewayOption } from './types';

const TYPE_LABELS: Record<string, string> = {
    mobile: 'Mobile money',
    crypto: 'Crypto',
    card: 'Cards',
};

/**
 * The gateway a reseller's own customers pay through.
 *
 * Optional by design — wallets can be credited by hand — so a reseller with
 * none connected gets a note explaining the trade-off, not a warning that
 * something is broken.
 */
export function PaymentsTab({
    gateways,
    connected,
}: {
    gateways: GatewayOption[];
    connected: ConnectedGateway[];
}) {
    // Ready gateways first: a reseller picking from the top gets one that
    // actually takes money today.
    const ordered = useMemo(
        () => [...gateways].sort((a, b) => Number(b.ready) - Number(a.ready)),
        [gateways],
    );

    const [selected, setSelected] = useState(ordered[0]?.code ?? '');
    const gateway = ordered.find((option) => option.code === selected);
    const isConnected = connected.some((row) => row.code === selected);

    const hasUsable = connected.some((row) => row.ready);

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
            preserveScroll: true,
            onSuccess: () => reset('credentials'),
        });
    };

    const disconnect = (code: string) => {
        router.delete(route('onboarding.payments.destroy', code), { preserveScroll: true });
    };

    return (
        <div className="space-y-6">
            {! hasUsable && (
                <NeedsAttention tone="info">
                    No gateway is taking payments, so customers cannot top up their own
                    wallets. Your bot still works — you credit wallets by hand.
                </NeedsAttention>
            )}

            {connected.length > 0 && (
                <Card title="Connected" description="Where your customers' top-ups go.">
                    <ul className="divide-y divide-border">
                        {connected.map((row) => (
                            <li key={row.code} className="flex items-center gap-2 py-2.5 first:pt-0 last:pb-0">
                                <Check className="size-4 shrink-0 text-primary" />
                                <span className="min-w-0 flex-1 truncate text-sm">{row.label}</span>

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
                </Card>
            )}

            <Card
                title={connected.length > 0 ? 'Add or update a gateway' : 'Add a gateway'}
                description="Keys are encrypted before we store them, and are never shown again once saved."
            >
                <form onSubmit={submit} className="max-w-xl space-y-6">
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
                                            {TYPE_LABELS[option.type ?? ''] ?? option.type}
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
                                        is.
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

                            <Button type="submit" disabled={processing}>
                                {processing && <Loader2 className="size-4 animate-spin" />}
                                {isConnected ? 'Update credentials' : `Connect ${gateway.label}`}
                            </Button>
                        </div>
                    )}
                </form>
            </Card>
        </div>
    );
}
