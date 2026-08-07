import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm } from '@inertiajs/react';
import { Check, Lock, X } from 'lucide-react';
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
                            us through. Credentials live in the server environment,
                            not the database: a key in the database is a key in every
                            backup and behind a session cookie rather than behind
                            server access. Changing one is a deploy.
                        </p>
                    </div>
                </div>

                <ul className="mt-4 space-y-2">
                    {gateways.map((gateway) => (
                        <li
                            key={gateway.code}
                            className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2 text-sm"
                        >
                            <div className="min-w-0">
                                <span className="block truncate font-medium">
                                    {gateway.label}
                                </span>
                                <span className="block font-mono text-xs text-muted-foreground">
                                    {gateway.code} · {gateway.type}
                                </span>
                            </div>

                            {gateway.configured ? (
                                <span className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-[#006300] dark:text-[#0ca30c]">
                                    <Check className="size-3.5" />
                                    Configured
                                </span>
                            ) : (
                                <span className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-muted-foreground">
                                    <X className="size-3.5" />
                                    No keys
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </section>
        </AdminLayout>
    );
}

const inputClass =
    'w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

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
