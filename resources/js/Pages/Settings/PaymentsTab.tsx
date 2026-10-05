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
 * Where each gateway belongs in the menu, by the customer's side of the world.
 * A gateway not listed here lands in "Other", so adding one to config never
 * makes it vanish from the form.
 */
const REGIONS: Array<{ title: string; codes: string[] }> = [
    { title: 'East Africa', codes: ['snippe', 'snippe_ke', 'snippe_ug', 'pesapal', 'zenopay', 'momopay'] },
    { title: 'West & Southern Africa', codes: ['fimipay_ng', 'fimipay_gh', 'fimipay_cm', 'fimipay_za', 'paystack', 'flutterwave'] },
    { title: 'Cards, worldwide', codes: ['fimipay_usd', 'stripe', 'paypal', 'razorpay'] },
    { title: 'Crypto', codes: ['nowpayments', 'binance', 'cryptomus', 'heleket'] },
];

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

    const grouped = useMemo(() => {
        const placed = new Set(REGIONS.flatMap((region) => region.codes));

        const groups = REGIONS.map((region) => ({
            title: region.title,
            options: region.codes
                .map((code) => ordered.find((option) => option.code === code))
                .filter((option): option is GatewayOption => option !== undefined),
        }));

        const other = ordered.filter((option) => ! placed.has(option.code));

        if (other.length > 0) {
            groups.push({ title: 'Other', options: other });
        }

        return groups.filter((group) => group.options.length > 0);
    }, [ordered]);

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
        <div className="space-y-4 sm:space-y-6">
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
                    {/* One menu grouped by where the customer is, instead of a
                        wall of twenty-one options. A native select, so a phone
                        shows its own picker. */}
                    <div>
                        <Label htmlFor="gateway">Gateway</Label>
                        <select
                            id="gateway"
                            value={selected}
                            onChange={(event) => choose(event.target.value)}
                            className="mt-1.5 h-11 w-full rounded-xl border border-input bg-transparent px-3 text-sm"
                        >
                            {grouped.map((group) => (
                                <optgroup key={group.title} label={group.title}>
                                    {group.options.map((option) => (
                                        <option key={option.code} value={option.code}>
                                            {option.label}
                                            {option.ready ? '' : ' — coming soon'}
                                            {connected.some((row) => row.code === option.code)
                                                ? ' ✓'
                                                : ''}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </select>

                        {gateway && (
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                {TYPE_LABELS[gateway.type ?? ''] ?? gateway.type}
                                {isConnected && ' · already connected — fill only what you want to change'}
                            </p>
                        )}

                        <FieldError message={errors.gateway} />
                    </div>

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

                            <Button type="submit" className="w-full sm:w-auto" disabled={processing}>
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
