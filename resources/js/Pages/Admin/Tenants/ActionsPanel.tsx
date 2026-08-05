import { router, useForm } from '@inertiajs/react';
import { KeyRound, Pencil, Power, Wallet } from 'lucide-react';
import { useState } from 'react';
import { money } from '../bits';
import { Abilities, TenantOverview } from '../types';

/**
 * Everything an admin can do to this reseller, in one column beside the data.
 *
 * Each action opens its own small form rather than living behind a menu: these
 * are consequential and infrequent, and the extra click of a dropdown buys
 * nothing when the panel has room to show what a button will actually do.
 *
 * Buttons the signed-in role cannot use are not rendered at all. The server
 * checks the same permission again — this only keeps the screen honest about
 * what it offers.
 */
export default function ActionsPanel({
    tenant,
    can,
}: {
    tenant: TenantOverview;
    can: Abilities;
}) {
    const [open, setOpen] = useState<'edit' | 'credit' | 'password' | null>(null);

    return (
        <aside className="space-y-3">
            <div className="rounded-xl border border-border bg-card p-4">
                <h2 className="font-heading text-sm font-bold">Actions</h2>

                <div className="mt-3 space-y-2">
                    {can.suspend && <SuspendButton tenant={tenant} />}

                    {can.edit && (
                        <ActionButton
                            icon={Pencil}
                            label="Edit details"
                            onClick={() => setOpen(open === 'edit' ? null : 'edit')}
                            active={open === 'edit'}
                        />
                    )}

                    {can.credit && (
                        <ActionButton
                            icon={Wallet}
                            label="Adjust credit"
                            hint={money(tenant.referralCredit)}
                            onClick={() => setOpen(open === 'credit' ? null : 'credit')}
                            active={open === 'credit'}
                        />
                    )}

                    {can.edit && (
                        <ActionButton
                            icon={KeyRound}
                            label="Reset password"
                            onClick={() =>
                                setOpen(open === 'password' ? null : 'password')
                            }
                            active={open === 'password'}
                        />
                    )}
                </div>
            </div>

            {open === 'edit' && (
                <EditForm tenant={tenant} onDone={() => setOpen(null)} />
            )}
            {open === 'credit' && (
                <CreditForm tenant={tenant} onDone={() => setOpen(null)} />
            )}
            {open === 'password' && (
                <PasswordForm tenant={tenant} onDone={() => setOpen(null)} />
            )}
        </aside>
    );
}

/**
 * Suspending asks for a reason and states the consequence.
 *
 * The wallet figure is in the confirmation because it is the thing an admin is
 * least likely to have in mind: suspending a reseller strands whatever their
 * customers have deposited, and that should be visible at the moment of
 * deciding rather than discovered afterwards.
 */
function SuspendButton({ tenant }: { tenant: TenantOverview }) {
    const [confirming, setConfirming] = useState(false);
    const [reason, setReason] = useState('');

    const suspended = tenant.status === 'suspended';

    if (suspended) {
        return (
            <button
                type="button"
                onClick={() =>
                    router.post(route('admin.tenants.act', [tenant.id, 'activate']))
                }
                className="flex w-full items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium transition-colors hover:bg-accent"
            >
                <Power className="size-4 text-[#006300] dark:text-[#0ca30c]" />
                Reactivate account
            </button>
        );
    }

    if (!confirming) {
        return (
            <button
                type="button"
                onClick={() => setConfirming(true)}
                className="flex w-full items-center gap-2 rounded-lg border border-destructive/30 px-3 py-2 text-sm font-medium text-destructive transition-colors hover:bg-destructive/10"
            >
                <Power className="size-4" />
                Suspend account
            </button>
        );
    }

    return (
        <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
            <p className="text-sm font-medium text-destructive">
                Suspend {tenant.name}?
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
                Their bots stop answering and they cannot log in. Nothing is
                deleted.
                {tenant.stats.walletsHeld > 0 && (
                    <>
                        {' '}
                        Their customers are holding{' '}
                        <span className="font-semibold text-foreground">
                            {money(tenant.stats.walletsHeld)}
                        </span>{' '}
                        in wallets.
                    </>
                )}
            </p>

            <input
                type="text"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Reason (optional)"
                maxLength={255}
                className="mt-2 w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm"
            />

            <div className="mt-2 flex gap-2">
                <button
                    type="button"
                    onClick={() => setConfirming(false)}
                    className="flex-1 rounded-lg border border-border px-2 py-1.5 text-xs transition-colors hover:bg-accent"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    onClick={() =>
                        router.post(
                            route('admin.tenants.act', [tenant.id, 'suspend']),
                            { reason },
                            { onSuccess: () => setConfirming(false) },
                        )
                    }
                    className="flex-1 rounded-lg bg-destructive px-2 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90"
                >
                    Suspend
                </button>
            </div>
        </div>
    );
}

function EditForm({
    tenant,
    onDone,
}: {
    tenant: TenantOverview;
    onDone: () => void;
}) {
    const { data, setData, patch, processing, errors } = useForm({
        business_name: tenant.name,
        email: tenant.email,
        phone: tenant.phone ?? '',
        lang: tenant.lang,
    });

    return (
        <Card title="Edit details">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    patch(route('admin.tenants.update', tenant.id), {
                        onSuccess: onDone,
                    });
                }}
                className="space-y-3"
            >
                <Field label="Business name" error={errors.business_name}>
                    <input
                        type="text"
                        value={data.business_name}
                        onChange={(e) => setData('business_name', e.target.value)}
                        className={inputClass}
                    />
                </Field>

                <Field label="Email" error={errors.email}>
                    <input
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className={inputClass}
                    />
                </Field>

                <Field label="Phone" error={errors.phone}>
                    <input
                        type="text"
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                        className={inputClass}
                    />
                </Field>

                <Field label="Language" error={errors.lang}>
                    <select
                        value={data.lang}
                        onChange={(e) => setData('lang', e.target.value)}
                        className={inputClass}
                    >
                        <option value="en">English</option>
                        <option value="sw">Swahili</option>
                        <option value="fr">French</option>
                    </select>
                </Field>

                <Buttons processing={processing} onCancel={onDone} submit="Save" />
            </form>
        </Card>
    );
}

/**
 * Referral credit is real money the reseller can spend at checkout, so the
 * reason is required and the resulting balance is previewed before submitting.
 */
function CreditForm({
    tenant,
    onDone,
}: {
    tenant: TenantOverview;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        delta: '',
        reason: '',
    });

    const delta = Number.parseFloat(data.delta);
    const preview = Number.isFinite(delta)
        ? Math.max(tenant.referralCredit + delta, 0)
        : null;

    return (
        <Card title="Adjust credit">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('admin.tenants.act', [tenant.id, 'credit']), {
                        onSuccess: onDone,
                    });
                }}
                className="space-y-3"
            >
                <p className="text-xs text-muted-foreground">
                    Current balance:{' '}
                    <span className="font-semibold text-foreground">
                        {money(tenant.referralCredit)}
                    </span>
                </p>

                <Field label="Amount (negative to deduct)" error={errors.delta}>
                    <input
                        type="number"
                        step="0.01"
                        value={data.delta}
                        onChange={(e) => setData('delta', e.target.value)}
                        placeholder="e.g. 25 or -10"
                        className={inputClass}
                    />
                </Field>

                {preview !== null && (
                    <p className="text-xs text-muted-foreground">
                        New balance:{' '}
                        <span className="font-semibold text-foreground">
                            {money(preview)}
                        </span>
                    </p>
                )}

                <Field label="Reason" error={errors.reason}>
                    <input
                        type="text"
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                        placeholder="e.g. goodwill for downtime on 3 Aug"
                        className={inputClass}
                    />
                </Field>

                <Buttons
                    processing={processing}
                    onCancel={onDone}
                    submit="Adjust credit"
                />
            </form>
        </Card>
    );
}

function PasswordForm({
    tenant,
    onDone,
}: {
    tenant: TenantOverview;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        password: '',
        password_confirmation: '',
    });

    return (
        <Card title="Reset password">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('admin.tenants.act', [tenant.id, 'password']), {
                        onSuccess: onDone,
                    });
                }}
                className="space-y-3"
            >
                <p className="text-xs text-muted-foreground">
                    Sets a new password immediately. Send it to the reseller over a
                    channel they already use — it is not emailed for them.
                </p>

                <Field label="New password" error={errors.password}>
                    <input
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        autoComplete="new-password"
                        className={inputClass}
                    />
                </Field>

                <Field label="Confirm password">
                    <input
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                        autoComplete="new-password"
                        className={inputClass}
                    />
                </Field>

                <Buttons
                    processing={processing}
                    onCancel={onDone}
                    submit="Set password"
                />
            </form>
        </Card>
    );
}

// ---- shared pieces -------------------------------------------------------

const inputClass =
    'w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

function ActionButton({
    icon: Icon,
    label,
    hint,
    onClick,
    active,
}: {
    icon: typeof Pencil;
    label: string;
    hint?: string;
    onClick: () => void;
    active: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={[
                'flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors',
                active
                    ? 'border-primary bg-accent'
                    : 'border-border hover:bg-accent',
            ].join(' ')}
        >
            <Icon className="size-4 text-muted-foreground" />
            <span className="flex-1 text-left">{label}</span>
            {hint && (
                <span className="text-xs tabular-nums text-muted-foreground">
                    {hint}
                </span>
            )}
        </button>
    );
}

function Card({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <h3 className="font-heading mb-3 text-sm font-bold">{title}</h3>
            {children}
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

function Buttons({
    processing,
    onCancel,
    submit,
}: {
    processing: boolean;
    onCancel: () => void;
    submit: string;
}) {
    return (
        <div className="flex gap-2 pt-1">
            <button
                type="button"
                onClick={onCancel}
                className="flex-1 rounded-lg border border-border px-2 py-1.5 text-xs transition-colors hover:bg-accent"
            >
                Cancel
            </button>
            <button
                type="submit"
                disabled={processing}
                className="flex-1 rounded-lg bg-primary px-2 py-1.5 text-xs font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-60"
            >
                {processing ? 'Saving…' : submit}
            </button>
        </div>
    );
}
