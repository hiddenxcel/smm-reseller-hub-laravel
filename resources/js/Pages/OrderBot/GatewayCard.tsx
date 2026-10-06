import { Button } from '@/components/ui/button';
import { router, useForm } from '@inertiajs/react';
import {
    Bitcoin,
    CircleCheck,
    CreditCard,
    Link2,
    Pause,
    Play,
    Smartphone,
    Star,
    Trash2,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import { Field, inputClass } from './bits';
import { GatewayOption } from './types';

const TYPE_ICON = {
    mobile: Smartphone,
    crypto: Bitcoin,
    card: CreditCard,
} as const;

/**
 * One gateway: what it is, whether it is on, and the credentials behind it.
 *
 * The form stays closed until asked for. A reseller with eight gateways listed
 * is here to check one of them, not to scroll past sixteen password inputs.
 */
export function GatewayCard({ gateway }: { gateway: GatewayOption }) {
    const [open, setOpen] = useState(false);

    const Icon = TYPE_ICON[gateway.type as keyof typeof TYPE_ICON] ?? CreditCard;
    const isOn = gateway.connected && gateway.status === 'active';

    return (
        <section className="rounded-xl border border-border bg-card p-5">
            <header className="flex flex-wrap items-start gap-3">
                <span
                    className="grid size-10 shrink-0 place-items-center rounded-xl bg-muted"
                    aria-hidden
                >
                    <Icon className="size-4 text-muted-foreground" />
                </span>

                <div className="min-w-0 flex-1">
                    <h3 className="font-heading truncate font-bold">{gateway.label}</h3>

                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        {gateway.connected ? (
                            <span
                                className={
                                    isOn ? 'font-medium text-primary' : 'font-medium'
                                }
                            >
                                {isOn ? 'Active' : 'Paused'}
                            </span>
                        ) : (
                            <span>Not connected</span>
                        )}

                        {gateway.needsPhone && <span>· asks for a phone number</span>}
                        {gateway.verify && <span>· payer reports a reference</span>}
                    </div>
                </div>

                {!gateway.ready && (
                    <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                        <Wrench className="size-3.5" aria-hidden />
                        Not wired up
                    </span>
                )}

                {gateway.ready && isOn && (
                    <span
                        className={[
                            'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                            gateway.isDefault
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-primary/10 text-primary',
                        ].join(' ')}
                    >
                        {gateway.isDefault ? (
                            <Star className="size-3.5 fill-current" aria-hidden />
                        ) : (
                            <CircleCheck className="size-3.5" aria-hidden />
                        )}
                        {gateway.isDefault ? 'Customers pay here' : 'Ready'}
                    </span>
                )}
            </header>

            {open ? (
                <CredentialsForm
                    gateway={gateway}
                    onDone={() => setOpen(false)}
                />
            ) : (
                <footer className="mt-4 flex flex-wrap justify-end gap-2">
                    {/* Pesapal's IPN id can only be obtained by asking Pesapal
                        for one, so this replaces a field the reseller could
                        never fill in by hand. */}
                    {gateway.registersIpn && gateway.connected && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    route('order-bot.gateways.register-ipn'),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Link2 className="size-4" />
                            Register notifications
                        </Button>
                    )}

                    {/* Only offered where it would mean something: a paused or
                        unwired gateway cannot be where customers pay. */}
                    {gateway.ready && isOn && !gateway.isDefault && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    route('order-bot.gateways.default', gateway.code),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Star className="size-4" />
                            Make default
                        </Button>
                    )}

                    {gateway.connected && (
                        <>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={() =>
                                    router.delete(
                                        route('order-bot.gateways.destroy', gateway.code),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                                Remove
                            </Button>

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        route('order-bot.gateways.toggle', gateway.code),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {isOn ? (
                                    <>
                                        <Pause className="size-4" />
                                        Pause
                                    </>
                                ) : (
                                    <>
                                        <Play className="size-4" />
                                        Resume
                                    </>
                                )}
                            </Button>
                        </>
                    )}

                    <Button
                        type="button"
                        variant={gateway.connected ? 'outline' : 'default'}
                        size="sm"
                        onClick={() => setOpen(true)}
                    >
                        {gateway.connected ? 'Edit keys' : 'Connect'}
                    </Button>
                </footer>
            )}
        </section>
    );
}

function CredentialsForm({
    gateway,
    onDone,
}: {
    gateway: GatewayOption;
    onDone: () => void;
}) {
    const form = useForm({
        gateway: gateway.code,
        credentials: Object.fromEntries(
            gateway.fields.map((field) => [field.name, '']),
        ) as Record<string, string>,
    });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('order-bot.gateways.store'), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="mt-4 space-y-4 border-t border-border pt-4"
        >
            {gateway.webhookUrl && (
                <Field
                    label="Notification URL"
                    hint="Paste this into your provider's project settings so it can tell us when a customer has paid."
                >
                    <input
                        readOnly
                        value={gateway.webhookUrl}
                        onFocus={(event) => event.currentTarget.select()}
                        className={inputClass}
                    />
                </Field>
            )}

            {gateway.fields.map((field) => (
                <Field
                    key={field.name}
                    label={field.label}
                    // Saying a value is stored, without showing it: the reseller
                    // needs to know typing nothing is safe.
                    hint={field.saved ? 'Saved — leave blank to keep it' : undefined}
                    error={form.errors[`credentials.${field.name}` as keyof typeof form.errors]}
                >
                    <input
                        type="password"
                        value={form.data.credentials[field.name] ?? ''}
                        onChange={(event) =>
                            form.setData('credentials', {
                                ...form.data.credentials,
                                [field.name]: event.target.value,
                            })
                        }
                        placeholder={field.saved ? '••••••••' : ''}
                        autoComplete="off"
                        className={inputClass}
                    />
                </Field>
            ))}

            <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    Cancel
                </Button>

                <Button type="submit" size="sm" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save'}
                </Button>
            </div>
        </form>
    );
}
