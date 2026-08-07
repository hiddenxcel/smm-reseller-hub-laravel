import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertCircle, KeyRound, Loader2, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Card, CopyBox, EmptyState, StatusChip, customerName, dateTime } from './bits';
import { ApiKeyRow, CustomerOption } from './types';

/**
 * Issuing and managing keys.
 *
 * A key belongs to one of the reseller's own customers, and spends that
 * customer's wallet — so the form asks who, not just what. That is the whole
 * mental model of this feature and the screen leads with it rather than
 * explaining it in help text.
 */
export function KeysTab({
    keys,
    customers,
    endpoint,
}: {
    keys: ApiKeyRow[];
    customers: CustomerOption[];
    endpoint: string;
}) {
    // The plaintext comes back in the flash bag exactly once, on the redirect
    // after issuing. There is no way to ask for it again, which the panel
    // below says plainly rather than leaving someone hunting for a button.
    const newKey = usePage().props.flash?.newApiKey as string | undefined;

    return (
        <div className="space-y-6">
            {newKey && <NewKeyPanel value={newKey} />}

            <Card
                title="Your endpoint"
                description="This is the URL your customers point their code at. It is the same for every key."
            >
                <CopyBox value={endpoint} label="endpoint URL" />
            </Card>

            <IssueForm customers={customers} />

            <Card
                title="Keys"
                description="Revoking a key stops it working immediately. The logs it left behind are kept."
            >
                {keys.length === 0 ? (
                    <EmptyState>
                        No keys yet. Issue one above to let a customer order without WhatsApp.
                    </EmptyState>
                ) : (
                    <ul className="divide-y divide-border">
                        {keys.map((key) => (
                            <KeyRow key={key.id} apiKey={key} />
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}

/**
 * Shown once, immediately after issuing.
 *
 * Deliberately loud and deliberately blunt about being unrepeatable: someone
 * who navigates away without copying has lost it, and the only remedy is a new
 * key. Saying so here costs nothing and saves a support conversation.
 */
function NewKeyPanel({ value }: { value: string }) {
    return (
        <section className="rounded-xl border border-primary/40 bg-primary/5 p-5">
            <div className="flex items-start gap-3">
                <KeyRound className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden />

                <div className="min-w-0 flex-1">
                    <h2 className="font-heading text-base font-bold">Here is the new key</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Copy it now and send it to your customer. We only store a fingerprint of
                        it, so this is the one and only time it can be shown — if it is lost,
                        issue a new one.
                    </p>

                    <CopyBox value={value} label="API key" className="mt-3" />
                </div>
            </div>
        </section>
    );
}

function IssueForm({ customers }: { customers: CustomerOption[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        customer_id: '',
        label: '',
    });

    return (
        <Card
            title="Issue a key"
            description="Pick the customer it belongs to. Orders placed with it come out of their wallet, exactly as they would over WhatsApp."
            footer={
                <Button
                    type="submit"
                    form="issue-key"
                    disabled={processing || data.customer_id === ''}
                >
                    {processing && <Loader2 className="size-4 animate-spin" />}
                    Issue key
                </Button>
            }
        >
            <form
                id="issue-key"
                onSubmit={(event) => {
                    event.preventDefault();
                    post(route('api-access.store'), {
                        preserveScroll: true,
                        onSuccess: () => reset(),
                    });
                }}
                className="grid gap-4 sm:grid-cols-2"
            >
                <div>
                    <Label htmlFor="customer_id">Customer</Label>

                    {customers.length === 0 ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            You have no customers yet. One is created the first time somebody
                            messages your bot.
                        </p>
                    ) : (
                        <select
                            id="customer_id"
                            value={data.customer_id}
                            onChange={(event) => setData('customer_id', event.target.value)}
                            className="mt-1.5 h-9 w-full rounded-lg border border-border bg-background px-3 text-sm"
                        >
                            <option value="">Choose a customer…</option>
                            {customers.map((customer) => (
                                <option key={customer.id} value={customer.id}>
                                    {customerName(customer)} — ${customer.balance}
                                </option>
                            ))}
                        </select>
                    )}

                    <FieldError message={errors.customer_id} />
                </div>

                <div>
                    <Label htmlFor="label">Label (optional)</Label>
                    <Input
                        id="label"
                        value={data.label}
                        onChange={(event) => setData('label', event.target.value)}
                        placeholder="e.g. Their live site"
                        className="mt-1.5"
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        For your own reference — the customer never sees it.
                    </p>
                    <FieldError message={errors.label} />
                </div>
            </form>
        </Card>
    );
}

function KeyRow({ apiKey }: { apiKey: ApiKeyRow }) {
    const [editing, setEditing] = useState(false);

    return (
        <li className="py-4 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <code className="font-mono text-sm">{apiKey.prefix}…</code>
                        <StatusChip status={apiKey.status} />
                        {apiKey.label && (
                            <span className="text-sm text-muted-foreground">{apiKey.label}</span>
                        )}
                    </div>

                    <p className="mt-1 text-sm text-muted-foreground">
                        {customerName(apiKey.customer)}
                        {' · '}
                        {apiKey.lastUsedAt
                            ? `last used ${dateTime(apiKey.lastUsedAt)}`
                            : 'never used'}
                    </p>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {apiKey.rateLimit ?? apiKey.defaultRateLimit} requests/minute
                        {apiKey.ipAllowlist.length > 0 &&
                            ` · only from ${apiKey.ipAllowlist.join(', ')}`}
                    </p>
                </div>

                {apiKey.status === 'active' && (
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing((open) => !open)}
                        >
                            {editing ? 'Cancel' : 'Limits'}
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                if (
                                    window.confirm(
                                        'Revoke this key? Any code using it stops working straight away.',
                                    )
                                ) {
                                    router.delete(route('api-access.destroy', apiKey.id), {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            <Trash2 className="size-3.5" />
                            Revoke
                        </Button>
                    </div>
                )}
            </div>

            {editing && <LimitsForm apiKey={apiKey} onDone={() => setEditing(false)} />}
        </li>
    );
}

function LimitsForm({ apiKey, onDone }: { apiKey: ApiKeyRow; onDone: () => void }) {
    // The IP list is edited as one textarea but sent as an array, and a blank
    // rate limit has to reach the server as null rather than "", so this form
    // shapes its own payload instead of posting the fields as typed.
    const { data, setData, processing, errors } = useForm({
        label: apiKey.label ?? '',
        rate_limit: apiKey.rateLimit?.toString() ?? '',
        ip_allowlist: apiKey.ipAllowlist.join('\n'),
    });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();

                router.patch(
                    route('api-access.update', apiKey.id),
                    {
                        label: data.label,
                        // Blank means "no limit set" — the server stores null
                        // and the platform default applies.
                        rate_limit: data.rate_limit === '' ? null : Number(data.rate_limit),
                        ip_allowlist: data.ip_allowlist
                            .split('\n')
                            .map((line) => line.trim())
                            .filter((line) => line !== ''),
                    },
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
            className="mt-4 grid gap-4 rounded-lg border border-border bg-muted/30 p-4 sm:grid-cols-3"
        >
            <div>
                <Label htmlFor={`label-${apiKey.id}`}>Label</Label>
                <Input
                    id={`label-${apiKey.id}`}
                    value={data.label}
                    onChange={(event) => setData('label', event.target.value)}
                    className="mt-1.5"
                />
                <FieldError message={errors.label} />
            </div>

            <div>
                <Label htmlFor={`rate-${apiKey.id}`}>Requests per minute</Label>
                <Input
                    id={`rate-${apiKey.id}`}
                    type="number"
                    min={1}
                    value={data.rate_limit}
                    onChange={(event) => setData('rate_limit', event.target.value)}
                    placeholder={apiKey.defaultRateLimit.toString()}
                    className="mt-1.5"
                />
                <p className="mt-1.5 text-xs text-muted-foreground">
                    Leave blank for the default ({apiKey.defaultRateLimit}).
                </p>
                <FieldError message={errors.rate_limit} />
            </div>

            <div>
                <Label htmlFor={`ips-${apiKey.id}`}>Allowed IPs</Label>
                <textarea
                    id={`ips-${apiKey.id}`}
                    value={data.ip_allowlist}
                    onChange={(event) => setData('ip_allowlist', event.target.value)}
                    rows={3}
                    placeholder="One per line"
                    className="mt-1.5 w-full rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm"
                />
                <p className="mt-1.5 text-xs text-muted-foreground">
                    Blank means anywhere.
                </p>
                <FieldError message={errors.ip_allowlist} />
            </div>

            <div className="sm:col-span-3">
                <Button type="submit" size="sm" disabled={processing}>
                    {processing && <Loader2 className="size-4 animate-spin" />}
                    Save
                </Button>
            </div>
        </form>
    );
}

function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p className="mt-2 flex items-start gap-1.5 text-sm text-destructive">
            <AlertCircle className="mt-0.5 size-4 shrink-0" />
            <span>{message}</span>
        </p>
    );
}
