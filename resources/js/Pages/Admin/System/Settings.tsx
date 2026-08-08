import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm } from '@inertiajs/react';
import { Check, Lock, X } from 'lucide-react';
import { useState } from 'react';
import { GatewayStatus, PlatformSettings } from '../types';

type Props = {
    settings: PlatformSettings;
    gateways: GatewayStatus[];
};

export default function Settings({ settings, gateways }: Props) {
    const { data, setData, post, processing, errors, isDirty } = useForm({
        company_name: settings.company_name ?? '',
        support_email: settings.support_email ?? '',
        support_whatsapp: settings.support_whatsapp ?? '',
        website_url: settings.website_url ?? '',
        terms_url: settings.terms_url ?? '',
        privacy_url: settings.privacy_url ?? '',
        referral_percent: String(settings.referral_percent ?? 10),
        registration_open: Boolean(settings.registration_open),
    });

    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Settings</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Platform-wide values, changeable without a deploy.
                    </p>
                </div>
            }
        >
            <Head title="Settings — Control" />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('admin.settings.save'));
                }}
                className="grid gap-4 lg:grid-cols-2"
            >
                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading mb-4 font-bold">Company</h2>

                    <div className="space-y-3">
                        <Field label="Company name" error={errors.company_name}>
                            <input
                                type="text"
                                value={data.company_name}
                                onChange={(e) => setData('company_name', e.target.value)}
                                className={inputClass}
                            />
                        </Field>

                        <Field label="Support email" error={errors.support_email}>
                            <input
                                type="email"
                                value={data.support_email}
                                onChange={(e) => setData('support_email', e.target.value)}
                                className={inputClass}
                            />
                        </Field>

                        <Field label="Support WhatsApp" error={errors.support_whatsapp}>
                            <input
                                type="text"
                                value={data.support_whatsapp}
                                onChange={(e) =>
                                    setData('support_whatsapp', e.target.value)
                                }
                                placeholder="255700000000"
                                className={inputClass}
                            />
                        </Field>

                        <Field label="Website" error={errors.website_url}>
                            <input
                                type="url"
                                value={data.website_url}
                                onChange={(e) => setData('website_url', e.target.value)}
                                placeholder="https://…"
                                className={inputClass}
                            />
                        </Field>
                    </div>
                </section>

                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading mb-4 font-bold">Business</h2>

                    <div className="space-y-3">
                        <Field
                            label="Referral percentage"
                            error={errors.referral_percent}
                        >
                            <input
                                type="number"
                                step="0.1"
                                min={0}
                                max={100}
                                value={data.referral_percent}
                                onChange={(e) =>
                                    setData('referral_percent', e.target.value)
                                }
                                className={inputClass}
                            />
                            <p className="mt-1 text-xs text-muted-foreground">
                                What a referrer earns of what they refer.
                            </p>
                        </Field>

                        <Field label="Registration">
                            <select
                                value={data.registration_open ? '1' : '0'}
                                onChange={(e) =>
                                    setData('registration_open', e.target.value === '1')
                                }
                                className={inputClass}
                            >
                                <option value="1">Open — anyone can sign up</option>
                                <option value="0">Closed — no new resellers</option>
                            </select>
                            <p className="mt-1 text-xs text-muted-foreground">
                                The switch worth having when something is on fire.
                            </p>
                        </Field>

                        <Field label="Terms URL" error={errors.terms_url}>
                            <input
                                type="url"
                                value={data.terms_url}
                                onChange={(e) => setData('terms_url', e.target.value)}
                                placeholder="https://…"
                                className={inputClass}
                            />
                        </Field>

                        <Field label="Privacy URL" error={errors.privacy_url}>
                            <input
                                type="url"
                                value={data.privacy_url}
                                onChange={(e) => setData('privacy_url', e.target.value)}
                                placeholder="https://…"
                                className={inputClass}
                            />
                        </Field>
                    </div>
                </section>

                <div className="lg:col-span-2">
                    <button
                        type="submit"
                        disabled={processing || !isDirty}
                        className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {processing ? 'Saving…' : 'Save settings'}
                    </button>
                </div>
            </form>

            <section className="mt-6 rounded-xl border border-border bg-card p-5">
                <div className="flex items-start gap-2">
                    <Lock className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div>
                        <h2 className="font-heading font-bold">Payment gateways</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            The platform's own merchant accounts — what resellers pay
                            us through. Not the gateways a reseller connects for their
                            own customers; those live in each reseller's dashboard.
                        </p>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Keys are encrypted before they are stored and are never
                            sent back to this screen — only the last four characters.
                            A value set in the server environment always wins, so a
                            gateway marked <span className="font-medium">env</span>
                            {' '}cannot be changed from here.
                        </p>
                    </div>
                </div>

                <div className="mt-4 space-y-3">
                    {gateways.map((gateway) => (
                        <GatewayCard key={gateway.code} gateway={gateway} />
                    ))}
                </div>
            </section>
        </AdminLayout>
    );
}

const inputClass =
    'w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

/**
 * One gateway, with its credentials editable.
 *
 * Values are write-only: the server never sends them back, so the inputs
 * start empty and a blank field means "leave what is stored" rather than
 * "clear it". Without that, editing a webhook secret would mean re-typing an
 * API key nobody can read.
 */
function GatewayCard({ gateway }: { gateway: GatewayStatus }) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        api_key: '',
        webhook_secret: '',
        extra: '',
        enabled: true,
    });

    // An environment value always wins, so offering a form here would let an
    // owner save something that silently has no effect.
    const locked = gateway.source === 'env';

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        post(route('admin.settings.gateway.save', gateway.code), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <div className="rounded-lg border border-border">
            <div className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                <div className="min-w-0">
                    <span className="block truncate text-sm font-medium">
                        {gateway.label}
                    </span>
                    <span className="block font-mono text-xs text-muted-foreground">
                        {gateway.code} · {gateway.type}
                        {gateway.hint && ` · key ${gateway.hint}`}
                    </span>
                </div>

                <div className="flex shrink-0 items-center gap-3">
                    {gateway.configured ? (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-[#006300] dark:text-[#0ca30c]">
                            <Check className="size-3.5" />
                            {gateway.source === 'env' ? 'From env' : 'Live'}
                        </span>
                    ) : gateway.source === 'stored-disabled' ? (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground">
                            Stored, switched off
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground">
                            <X className="size-3.5" />
                            No keys
                        </span>
                    )}

                    {! locked && (
                        <button
                            type="button"
                            onClick={() => setOpen((value) => ! value)}
                            className="rounded-lg border border-border px-2.5 py-1 text-xs font-medium transition-colors hover:bg-accent"
                        >
                            {open ? 'Cancel' : gateway.configured ? 'Replace keys' : 'Add keys'}
                        </button>
                    )}
                </div>
            </div>

            {locked && (
                <p className="border-t border-border px-3 py-2 text-xs text-muted-foreground">
                    Set in the server environment, which takes precedence. Remove it
                    from .env to manage this gateway here.
                </p>
            )}

            {open && ! locked && (
                <form onSubmit={submit} className="space-y-3 border-t border-border p-3">
                    {gateway.help && (
                        <p className="text-xs text-muted-foreground">{gateway.help}</p>
                    )}

                    {gateway.fields.map((field) => (
                        <div key={field.name}>
                            <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                {field.label}
                            </label>
                            <input
                                type="password"
                                autoComplete="new-password"
                                value={data[field.name as 'api_key' | 'webhook_secret' | 'extra']}
                                onChange={(e) =>
                                    setData(
                                        field.name as 'api_key' | 'webhook_secret' | 'extra',
                                        e.target.value,
                                    )
                                }
                                placeholder={gateway.configured ? 'Leave blank to keep' : ''}
                                className={inputClass}
                            />
                            {errors[field.name as keyof typeof errors] && (
                                <p className="mt-1 text-xs text-destructive">
                                    {errors[field.name as keyof typeof errors]}
                                </p>
                            )}
                        </div>
                    ))}

                    <label className="flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            checked={data.enabled}
                            onChange={(e) => setData('enabled', e.target.checked)}
                            className="size-3.5 accent-primary"
                        />
                        Offer this to resellers at checkout
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {processing ? 'Saving…' : 'Save'}
                    </button>
                </form>
            )}
        </div>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="mb-1 block text-xs font-medium text-muted-foreground">
                {label}
            </label>
            {children}
            {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
        </div>
    );
}
