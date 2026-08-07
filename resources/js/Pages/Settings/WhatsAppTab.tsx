import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { Copy, Loader2, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { Card, EmptyState, FieldError, NeedsAttention } from './bits';
import { RentableNumber, Rental, WhatsAppNumber } from './types';

const BOT_LABELS: Record<string, string> = {
    order: 'Order bot',
    support: 'Support bot',
};

/**
 * The numbers the bots answer on.
 *
 * Two ways in, kept side by side rather than one behind the other: bringing
 * your own Meta number, or renting one from the platform. Renting skips the
 * whole Meta setup, which is where most resellers stall.
 */
export function WhatsAppTab({
    webhookUrl,
    verifyToken,
    numbers,
    rentable,
    rentals,
}: {
    webhookUrl: string;
    verifyToken: string;
    numbers: WhatsAppNumber[];
    rentable: RentableNumber[];
    rentals: Rental[];
}) {
    return (
        <div className="space-y-6">
            {numbers.length === 0 && (
                <NeedsAttention>
                    No WhatsApp number is connected, so your bots cannot answer anyone.
                </NeedsAttention>
            )}

            <Card title="Connected numbers" description="What your customers message.">
                {numbers.length === 0 ? (
                    <EmptyState>Nothing connected yet.</EmptyState>
                ) : (
                    <ul className="divide-y divide-border">
                        {numbers.map((number) => (
                            <li
                                key={number.id}
                                className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="font-data text-sm">
                                        {number.display_number ?? number.phone_number_id}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {BOT_LABELS[number.bot_type] ?? number.bot_type}
                                        {number.source === 'rented' && ' · rented from us'}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {rentals.length > 0 && (
                <Card title="Rented numbers" description="Billed monthly while active.">
                    <ul className="divide-y divide-border">
                        {rentals.map((rental) => (
                            <li
                                key={rental.id}
                                className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="font-data text-sm">{rental.display_number}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {rental.country}
                                    </p>
                                </div>

                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.delete(
                                            route('onboarding.whatsapp.release', rental.id),
                                            { preserveScroll: true },
                                        )
                                    }
                                    aria-label={`Release ${rental.display_number}`}
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <RentCard rentable={rentable} />

            <ConnectOwnCard webhookUrl={webhookUrl} verifyToken={verifyToken} />
        </div>
    );
}

function RentCard({ rentable }: { rentable: RentableNumber[] }) {
    if (rentable.length === 0) {
        return null;
    }

    return (
        <Card
            title="Rent a number"
            description="Skips the Meta setup entirely — we hold the credentials, you drive the bot."
        >
            <ul className="divide-y divide-border">
                {rentable.map((number) => (
                    <li
                        key={number.id}
                        className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div className="min-w-0 flex-1">
                            <p className="font-data text-sm">{number.display_number}</p>
                            <p className="text-xs text-muted-foreground">{number.country}</p>
                        </div>

                        <span className="text-sm">
                            {number.currency} {number.price}
                            <span className="text-muted-foreground">/mo</span>
                        </span>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    route('onboarding.whatsapp.rent'),
                                    { platform_number_id: number.id },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Rent
                        </Button>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/**
 * Bringing your own Meta number.
 *
 * Collapsed by default — most resellers rent — but the webhook details stay
 * visible inside it, since someone reconnecting a number needs them again.
 */
function ConnectOwnCard({
    webhookUrl,
    verifyToken,
}: {
    webhookUrl: string;
    verifyToken: string;
}) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        phone_number_id: '',
        token: '',
        waba_id: '',
        display_number: '',
        bot_type: 'order',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('onboarding.whatsapp.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    if (! open) {
        return (
            <Button variant="outline" onClick={() => setOpen(true)}>
                Connect your own Meta number
            </Button>
        );
    }

    return (
        <Card
            title="Connect your own number"
            description="Paste these two into your Meta app so Meta knows where to deliver messages."
        >
            <div className="mb-6 space-y-3">
                <CopyRow label="Webhook URL" value={webhookUrl} />
                <CopyRow label="Verify token" value={verifyToken} />
            </div>

            <form onSubmit={submit} className="max-w-lg space-y-5">
                <div>
                    <Label htmlFor="phone_number_id">Phone number ID</Label>
                    <Input
                        id="phone_number_id"
                        value={data.phone_number_id}
                        onChange={(event) => setData('phone_number_id', event.target.value)}
                        className="mt-1.5"
                        required
                    />
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
                    <FieldError message={errors.token} />
                </div>

                <div>
                    <Label htmlFor="display_number">Display number</Label>
                    <Input
                        id="display_number"
                        value={data.display_number}
                        onChange={(event) => setData('display_number', event.target.value)}
                        placeholder="+255…"
                        className="mt-1.5"
                    />
                    <FieldError message={errors.display_number} />
                </div>

                <div>
                    <Label htmlFor="bot_type">Which bot answers on it</Label>
                    <select
                        id="bot_type"
                        value={data.bot_type}
                        onChange={(event) => setData('bot_type', event.target.value)}
                        className="mt-1.5 h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option value="order">Order bot</option>
                        <option value="support">Support bot</option>
                    </select>
                    <FieldError message={errors.bot_type} />
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        Connect number
                    </Button>

                    <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function CopyRow({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <div>
            <p className="mb-1 text-xs font-medium text-muted-foreground">{label}</p>
            <div className="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2">
                <code className="font-data min-w-0 flex-1 truncate text-xs">{value}</code>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        navigator.clipboard?.writeText(value);
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    }}
                    aria-label={`Copy ${label}`}
                >
                    {copied ? 'Copied' : <Copy className="size-3.5" />}
                </Button>
            </div>
        </div>
    );
}
