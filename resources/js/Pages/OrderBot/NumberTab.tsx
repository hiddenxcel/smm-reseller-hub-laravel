import PhoneInput from '@/components/PhoneInput';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { Copy, Loader2, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { Card, EmptyState, FieldError, NeedsAttention } from '../Settings/bits';

export type BotNumber = {
    id: number;
    display_number: string | null;
    phone_number_id: string;
    source: string;
    status: string;
    rental_id: number | null;
    country: string | null;
};

export type BotRentable = {
    id: number;
    display_number: string | null;
    country: string | null;
    currency: string | null;
    price: number;
};

export type BotNumbersData = {
    bot: 'order' | 'support';
    webhookUrl: string;
    verifyToken: string;
    numbers: BotNumber[];
    rentable: BotRentable[];
};

const BOT_NAMES = { order: 'Order bot', support: 'Support bot' } as const;

/**
 * The number a bot answers on, managed from the bot itself: buy one, connect
 * your own, or let one go. Each bot has one number, so connecting or renting
 * is offered only while it has none; to swap, remove the current one first.
 */
export function NumberTab({ data }: { data: BotNumbersData }) {
    const name = BOT_NAMES[data.bot];
    const current = data.numbers[0];

    return (
        <div className="space-y-4 sm:space-y-6">
            {! current && (
                <NeedsAttention>
                    The {name.toLowerCase()} has no number yet, so it cannot answer anyone. Buy one
                    below or connect your own.
                </NeedsAttention>
            )}

            <Card title={`${name} number`} description="What your customers message.">
                {! current ? (
                    <EmptyState>No number connected.</EmptyState>
                ) : (
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="min-w-0 flex-1">
                            <p className="font-data text-sm">
                                {current.display_number ?? current.phone_number_id}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {current.source === 'rented'
                                    ? `Rented from us${current.country ? ` · ${current.country}` : ''} · billed monthly`
                                    : 'Your own number'}
                                {current.status !== 'active' && ' · not active'}
                            </p>
                        </div>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                const label = current.display_number ?? current.phone_number_id;
                                const rented = current.rental_id !== null;

                                if (
                                    window.confirm(
                                        rented
                                            ? `Release ${label}? It goes back to the pool and the bot stops answering on it.`
                                            : `Disconnect ${label}? It stays yours in Meta — we just stop answering on it.`,
                                    )
                                ) {
                                    router.delete(
                                        rented
                                            ? route('onboarding.whatsapp.release', current.rental_id!)
                                            : route('onboarding.whatsapp.disconnect', current.id),
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            <Trash2 className="size-3.5" />
                            {current.rental_id !== null ? 'Release' : 'Remove'}
                        </Button>
                    </div>
                )}
            </Card>

            {! current && (
                <>
                    <RentCard bot={data.bot} rentable={data.rentable} />
                    <ConnectOwnCard data={data} />
                </>
            )}
        </div>
    );
}

function RentCard({ bot, rentable }: { bot: string; rentable: BotRentable[] }) {
    return (
        <Card
            title="Buy a number"
            description="Skips the Meta setup — we hold the credentials, you drive the bot. It is yours once the payment clears."
        >
            {rentable.length === 0 ? (
                <EmptyState>No numbers for sale right now. Connect your own below.</EmptyState>
            ) : (
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
                                    router.post(route('onboarding.whatsapp.rent'), {
                                        platform_number_id: number.id,
                                        bot_type: bot,
                                    })
                                }
                            >
                                Choose &amp; pay
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function ConnectOwnCard({ data }: { data: BotNumbersData }) {
    const [open, setOpen] = useState(false);

    const { data: form, setData, post, processing, errors, reset } = useForm({
        phone_number_id: '',
        token: '',
        waba_id: '',
        display_number: '',
        bot_type: data.bot,
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
            <Button variant="outline" className="w-full sm:w-auto" onClick={() => setOpen(true)}>
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
                <CopyRow label="Webhook URL" value={data.webhookUrl} />
                <CopyRow label="Verify token" value={data.verifyToken} />
            </div>

            <form onSubmit={submit} className="max-w-lg space-y-5">
                <div>
                    <Label htmlFor="phone_number_id">Phone number ID</Label>
                    <Input
                        id="phone_number_id"
                        value={form.phone_number_id}
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
                        value={form.token}
                        onChange={(event) => setData('token', event.target.value)}
                        className="mt-1.5"
                        required
                    />
                    <FieldError message={errors.token} />
                </div>

                <div>
                    <Label htmlFor="display_number">Display number</Label>
                    <PhoneInput
                        id="display_number"
                        value={form.display_number}
                        onChange={(value) => setData('display_number', value)}
                        className="mt-1.5"
                    />
                    <FieldError message={errors.display_number} />
                </div>

                <FieldError message={errors.bot_type} />

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
            <div className="flex items-center gap-2 rounded-xl border border-border bg-muted/40 px-3 py-2">
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
