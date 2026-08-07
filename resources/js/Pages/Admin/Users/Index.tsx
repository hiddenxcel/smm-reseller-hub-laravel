import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, Power, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { DataTable, dateTime } from '../bits';
import { AdminUserRow } from '../types';

type Props = {
    admins: AdminUserRow[];
    roles: string[];
};

const ROLE_BLURBS: Record<string, string> = {
    owner: 'Everything, including managing admins and settings.',
    admin: 'Resellers, billing and tickets. Not admins or settings.',
    support: 'Look at accounts and answer tickets. No money, no suspensions.',
};

export default function AdminUsersIndex({ admins, roles }: Props) {
    const [editing, setEditing] = useState<AdminUserRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [password, setPassword] = useState<AdminUserRow | null>(null);

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-extrabold">
                            Admin users
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Who can operate this console.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={() => {
                            setEditing(null);
                            setCreating(true);
                        }}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                    >
                        <Plus className="size-4" />
                        New admin
                    </button>
                </div>
            }
        >
            <Head title="Admin users — Control" />

            <div className="grid gap-3 sm:grid-cols-3">
                {roles.map((role) => (
                    <div key={role} className="rounded-xl border border-border bg-card p-4">
                        <p className="font-heading text-sm font-bold capitalize">{role}</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {ROLE_BLURBS[role]}
                        </p>
                    </div>
                ))}
            </div>

            <DataTable
                headers={[
                    { label: 'Admin' },
                    { label: 'Role' },
                    { label: 'Status' },
                    { label: 'Last sign-in' },
                    { label: 'Actions', align: 'right' },
                ]}
            >
                {admins.map((admin) => (
                    <tr key={admin.id} className="border-b border-border last:border-0">
                        <td className="px-4 py-3">
                            <span className="block font-medium">
                                {admin.username}
                                {admin.isSelf && (
                                    <span className="ml-2 text-xs text-muted-foreground">
                                        you
                                    </span>
                                )}
                            </span>
                            {(admin.name || admin.email) && (
                                <span className="block truncate text-xs text-muted-foreground">
                                    {[admin.name, admin.email].filter(Boolean).join(' · ')}
                                </span>
                            )}
                        </td>
                        <td className="px-4 py-3">
                            <span
                                className={[
                                    'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium capitalize',
                                    admin.role === 'owner'
                                        ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                        : 'bg-muted text-muted-foreground',
                                ].join(' ')}
                            >
                                {admin.role === 'owner' && (
                                    <ShieldCheck className="size-3" aria-hidden />
                                )}
                                {admin.role}
                            </span>
                        </td>
                        <td className="px-4 py-3">
                            <span
                                className={[
                                    'inline-flex rounded px-1.5 py-0.5 text-xs font-medium',
                                    admin.status === 'active'
                                        ? 'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]'
                                        : 'bg-destructive/10 text-destructive',
                                ].join(' ')}
                            >
                                {admin.status}
                            </span>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">
                            {admin.lastLoginAt ? (
                                <>
                                    <span className="block">
                                        {dateTime(admin.lastLoginAt)}
                                    </span>
                                    {admin.lastLoginIp && (
                                        <span className="block font-mono text-xs">
                                            {admin.lastLoginIp}
                                        </span>
                                    )}
                                </>
                            ) : (
                                'Never'
                            )}
                        </td>
                        <td className="px-4 py-3">
                            <div className="flex justify-end gap-1.5">
                                <IconButton
                                    icon={Pencil}
                                    label="Edit"
                                    onClick={() => {
                                        setCreating(false);
                                        setEditing(admin);
                                    }}
                                />
                                <IconButton
                                    icon={KeyRound}
                                    label="Set password"
                                    onClick={() => setPassword(admin)}
                                />
                                {!admin.isSelf && (
                                    <IconButton
                                        icon={Power}
                                        label={
                                            admin.status === 'active' ? 'Disable' : 'Enable'
                                        }
                                        destructive={admin.status === 'active'}
                                        onClick={() =>
                                            router.post(
                                                route('admin.admins.act', [
                                                    admin.id,
                                                    admin.status === 'active'
                                                        ? 'disable'
                                                        : 'enable',
                                                ]),
                                            )
                                        }
                                    />
                                )}
                            </div>
                        </td>
                    </tr>
                ))}
            </DataTable>

            <p className="mt-3 text-xs text-muted-foreground">
                Admins are disabled rather than deleted — every audit entry points
                at an id, and removing one would orphan the trail that explains why
                a reseller's account changed.
            </p>

            {(editing || creating) && (
                <AdminDialog
                    admin={editing}
                    roles={roles}
                    onClose={() => {
                        setEditing(null);
                        setCreating(false);
                    }}
                />
            )}

            {password && (
                <PasswordDialog admin={password} onClose={() => setPassword(null)} />
            )}
        </AdminLayout>
    );
}

function IconButton({
    icon: Icon,
    label,
    onClick,
    destructive = false,
}: {
    icon: typeof Pencil;
    label: string;
    onClick: () => void;
    destructive?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={label}
            aria-label={label}
            className={[
                'rounded-lg border p-1.5 transition-colors',
                destructive
                    ? 'border-destructive/30 text-destructive hover:bg-destructive/10'
                    : 'border-border text-muted-foreground hover:bg-accent',
            ].join(' ')}
        >
            <Icon className="size-3.5" />
        </button>
    );
}

function AdminDialog({
    admin,
    roles,
    onClose,
}: {
    admin: AdminUserRow | null;
    roles: string[];
    onClose: () => void;
}) {
    const isNew = admin === null;

    const { data, setData, post, patch, processing, errors } = useForm({
        username: admin?.username ?? '',
        name: admin?.name ?? '',
        email: admin?.email ?? '',
        role: admin?.role ?? 'support',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isNew) {
            post(route('admin.admins.store'), { onSuccess: onClose });
        } else {
            patch(route('admin.admins.update', admin.id), { onSuccess: onClose });
        }
    };

    return (
        <Dialog title={isNew ? 'New admin' : `Edit ${admin.username}`} onSubmit={submit}>
            {isNew && (
                <Field label="Username" error={errors.username}>
                    <input
                        type="text"
                        value={data.username}
                        onChange={(e) => setData('username', e.target.value)}
                        autoFocus
                        className={inputClass}
                    />
                </Field>
            )}

            <Field label="Name" error={errors.name}>
                <input
                    type="text"
                    value={data.name ?? ''}
                    onChange={(e) => setData('name', e.target.value)}
                    className={inputClass}
                />
            </Field>

            <Field label="Email" error={errors.email}>
                <input
                    type="email"
                    value={data.email ?? ''}
                    onChange={(e) => setData('email', e.target.value)}
                    className={inputClass}
                />
            </Field>

            <Field label="Role" error={errors.role}>
                <select
                    value={data.role}
                    onChange={(e) => setData('role', e.target.value)}
                    className={inputClass}
                >
                    {roles.map((role) => (
                        <option key={role} value={role}>
                            {role}
                        </option>
                    ))}
                </select>
                <p className="mt-1 text-xs text-muted-foreground">
                    {ROLE_BLURBS[data.role]}
                </p>
            </Field>

            {isNew && (
                <>
                    <Field label="Password (12 characters or more)" error={errors.password}>
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
                </>
            )}

            <Buttons
                processing={processing}
                onCancel={onClose}
                submit={isNew ? 'Create admin' : 'Save changes'}
            />
        </Dialog>
    );
}

function PasswordDialog({
    admin,
    onClose,
}: {
    admin: AdminUserRow;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        password: '',
        password_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        post(route('admin.admins.act', [admin.id, 'password']), {
            onSuccess: onClose,
        });
    };

    return (
        <Dialog title={`Set password for ${admin.username}`} onSubmit={submit}>
            <p className="text-xs text-muted-foreground">
                Send it over a channel they already use — it is not emailed for
                them, and it is never written to the audit trail.
            </p>

            <Field label="New password (12 characters or more)" error={errors.password}>
                <input
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    autoComplete="new-password"
                    autoFocus
                    className={inputClass}
                />
            </Field>

            <Field label="Confirm password">
                <input
                    type="password"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    autoComplete="new-password"
                    className={inputClass}
                />
            </Field>

            <Buttons processing={processing} onCancel={onClose} submit="Set password" />
        </Dialog>
    );
}

// ---- shared pieces -------------------------------------------------------

const inputClass =
    'w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

function Dialog({
    title,
    onSubmit,
    children,
}: {
    title: string;
    onSubmit: (e: React.FormEvent) => void;
    children: React.ReactNode;
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-foreground/40 p-4">
            <form
                onSubmit={onSubmit}
                className="my-8 w-full max-w-md space-y-3 rounded-xl border border-border bg-card p-6"
            >
                <h2 className="font-heading text-lg font-extrabold">{title}</h2>
                {children}
            </form>
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
        <div className="flex justify-end gap-2 pt-2">
            <button
                type="button"
                onClick={onCancel}
                className="rounded-lg border border-border px-3 py-2 text-sm transition-colors hover:bg-accent"
            >
                Cancel
            </button>
            <button
                type="submit"
                disabled={processing}
                className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-60"
            >
                {processing ? 'Saving…' : submit}
            </button>
        </div>
    );
}
