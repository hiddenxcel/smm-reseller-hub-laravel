import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Link2, Loader2, Plus, Trash2, UserPlus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Role = { value: string; label: string; description: string };

type Member = {
    id: number;
    name: string | null;
    email: string;
    role: string;
    status: 'active' | 'pending' | 'expired';
    lastLoginAt: string | null;
    invitedAt: string | null;
};

type Props = {
    members: Member[];
    roles: Role[];
    limit: number;
    inviteDays: number;
    invite: { email: string; url: string } | null;
};

/**
 * Who else can get into this account, and what each of them can do.
 *
 * Invites are links, not emails: the link exists once, here, right after it is
 * made, and is not stored anywhere to show again — so the page says so, and a
 * lost one is replaced rather than recovered.
 */
export default function TeamIndex({ members, roles, limit, inviteDays, invite }: Props) {
    const owner = usePage().props.auth.user;
    const [inviting, setInviting] = useState(members.length === 0);

    const full = members.length >= limit;

    return (
        <AuthenticatedLayout>
            <Head title="Team" />

            <div className="mx-auto max-w-3xl space-y-4 sm:space-y-6">
                <header className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                            Team
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            People who can help run your shop, each with a role.
                        </p>
                    </div>

                    <span className="font-data shrink-0 rounded-full bg-muted px-3 py-1.5 text-sm text-muted-foreground">
                        {members.length} of {limit}
                    </span>
                </header>

                {invite && <InviteLink invite={invite} days={inviteDays} />}

                <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                    <li className="flex items-center gap-3 p-4">
                        <Avatar label={owner.business_name} />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">{owner.business_name}</p>
                            <p className="truncate text-xs text-muted-foreground">{owner.email}</p>
                        </div>
                        <span className="shrink-0 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                            Owner
                        </span>
                    </li>

                    {members.map((member) => (
                        <MemberRow key={member.id} member={member} roles={roles} />
                    ))}
                </ul>

                {full ? (
                    <p className="rounded-2xl border border-dashed border-border px-4 py-4 text-center text-sm text-muted-foreground">
                        Your team is full. Remove someone to invite another person.
                    </p>
                ) : inviting ? (
                    <InviteForm
                        roles={roles}
                        onCancel={members.length > 0 ? () => setInviting(false) : undefined}
                    />
                ) : (
                    <Button
                        variant="outline"
                        className="w-full sm:w-auto"
                        onClick={() => setInviting(true)}
                    >
                        <Plus className="size-4" />
                        Invite someone
                    </Button>
                )}

                <RolesGuide roles={roles} />
            </div>
        </AuthenticatedLayout>
    );
}

function InviteForm({ roles, onCancel }: { roles: Role[]; onCancel?: () => void }) {
    const form = useForm({ email: '', name: '', role: 'support' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('team.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const chosen = roles.find((role) => role.value === form.data.role);

    return (
        <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <div className="mb-4 flex items-center gap-2">
                <UserPlus className="size-4 text-muted-foreground" aria-hidden />
                <h2 className="font-heading text-base font-bold">Invite someone</h2>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <Label htmlFor="invite_email">Their email</Label>
                    <Input
                        id="invite_email"
                        type="email"
                        className="mt-1.5 h-10"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        placeholder="name@example.com"
                        autoComplete="off"
                        required
                    />
                    {form.errors.email && (
                        <p className="mt-1.5 text-sm text-destructive">{form.errors.email}</p>
                    )}
                </div>

                <div>
                    <Label htmlFor="invite_name">Their name</Label>
                    <p className="text-xs text-muted-foreground">Optional — they can fill it in.</p>
                    <Input
                        id="invite_name"
                        className="mt-1.5 h-10"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        autoComplete="off"
                    />
                </div>

                <div>
                    <Label htmlFor="invite_role">Role</Label>
                    <select
                        id="invite_role"
                        value={form.data.role}
                        onChange={(event) => form.setData('role', event.target.value)}
                        className="mt-1.5 h-10 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        {roles.map((role) => (
                            <option key={role.value} value={role.value}>
                                {role.label}
                            </option>
                        ))}
                    </select>
                    {chosen && (
                        <p className="mt-1.5 text-xs text-muted-foreground">{chosen.description}</p>
                    )}
                    {form.errors.role && (
                        <p className="mt-1.5 text-sm text-destructive">{form.errors.role}</p>
                    )}
                </div>

                <div className="flex flex-col gap-2 sm:flex-row-reverse">
                    <Button type="submit" className="w-full sm:w-auto" disabled={form.processing}>
                        {form.processing && <Loader2 className="size-4 animate-spin" />}
                        Create invite link
                    </Button>
                    {onCancel && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="w-full sm:w-auto"
                            onClick={onCancel}
                        >
                            Cancel
                        </Button>
                    )}
                </div>
            </form>
        </section>
    );
}

/** The link, shown once. It is not stored anywhere that could show it again. */
function InviteLink({ invite, days }: { invite: { email: string; url: string }; days: number }) {
    const [copied, setCopied] = useState(false);

    return (
        <section className="rounded-2xl border border-primary/30 bg-primary/5 p-4 sm:p-5">
            <div className="flex items-center gap-2">
                <Link2 className="size-4 text-primary" aria-hidden />
                <h2 className="font-heading text-base font-bold">Send this link to {invite.email}</h2>
            </div>

            <div className="mt-3 flex items-center gap-2 rounded-xl border border-border bg-background px-3 py-2.5">
                <code className="font-data min-w-0 flex-1 truncate text-xs">{invite.url}</code>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        navigator.clipboard?.writeText(invite.url);
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1800);
                    }}
                >
                    {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>

            <p className="mt-2 text-xs text-muted-foreground">
                Works once, for {days} days. It is shown only now — if you lose it, make a new link
                for them and the old one stops working.
            </p>
        </section>
    );
}

function MemberRow({ member, roles }: { member: Member; roles: Role[] }) {
    const [busy, setBusy] = useState(false);

    const changeRole = (role: string) => {
        setBusy(true);
        router.patch(
            route('team.update', member.id),
            { role },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const newLink = () => {
        setBusy(true);
        router.post(route('team.link', member.id), {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const remove = () => {
        if (!window.confirm(`Remove ${member.name ?? member.email}? Their access ends on their next click.`)) {
            return;
        }

        setBusy(true);
        router.delete(route('team.destroy', member.id), {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <li className="space-y-3 p-4">
            <div className="flex items-center gap-3">
                <Avatar label={member.name ?? member.email} />

                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{member.name ?? member.email}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {member.name && `${member.email} · `}
                        {status(member)}
                    </p>
                </div>

                <StatusBadge status={member.status} />
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <select
                    value={member.role}
                    onChange={(event) => changeRole(event.target.value)}
                    disabled={busy}
                    aria-label={`Role for ${member.name ?? member.email}`}
                    className="h-9 min-w-[7.5rem] rounded-lg border border-input bg-background px-2.5 text-sm"
                >
                    {roles.map((role) => (
                        <option key={role.value} value={role.value}>
                            {role.label}
                        </option>
                    ))}
                </select>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onClick={newLink}
                    title="Make a new link — the old one stops working"
                >
                    <Link2 className="size-3.5" />
                    {member.status === 'active' ? 'Reset link' : 'New link'}
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={busy}
                    onClick={remove}
                    className="text-destructive hover:text-destructive"
                    aria-label={`Remove ${member.name ?? member.email}`}
                >
                    <Trash2 className="size-3.5" />
                    Remove
                </Button>
            </div>
        </li>
    );
}

function status(member: Member): string {
    if (member.status === 'active') {
        return member.lastLoginAt
            ? `Last signed in ${new Date(member.lastLoginAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`
            : 'Active';
    }

    return member.status === 'pending' ? 'Has not joined yet' : 'The invite link has expired';
}

function StatusBadge({ status }: { status: Member['status'] }) {
    const styles = {
        active: 'bg-primary/10 text-primary',
        pending:
            'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]',
        expired: 'bg-muted text-muted-foreground',
    }[status];

    const label = { active: 'Active', pending: 'Invited', expired: 'Expired' }[status];

    return (
        <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${styles}`}>
            {label}
        </span>
    );
}

function RolesGuide({ roles }: { roles: Role[] }) {
    return (
        <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <h2 className="font-heading mb-3 text-base font-bold">What each role can do</h2>
            <dl className="space-y-3">
                {roles.map((role) => (
                    <div key={role.value}>
                        <dt className="text-sm font-semibold">{role.label}</dt>
                        <dd className="text-sm text-muted-foreground">{role.description}</dd>
                    </div>
                ))}
            </dl>
            <p className="mt-3 border-t border-border pt-3 text-xs text-muted-foreground">
                Billing, payment gateways, API keys, connected panels and numbers, your profile and
                this team are always yours alone.
            </p>
        </section>
    );
}

function Avatar({ label }: { label: string }) {
    let hash = 0;

    for (const char of label) {
        hash = (hash * 31 + char.charCodeAt(0)) % 360;
    }

    const parts = label.trim().split(/[\s@.]+/).filter(Boolean);
    const initials = (parts.length > 1 ? parts[0][0] + parts[1][0] : (parts[0] ?? '?').slice(0, 2)).toUpperCase();

    return (
        <span
            className="grid size-10 shrink-0 place-items-center rounded-full text-xs font-bold"
            style={{
                backgroundColor: `oklch(0.92 0.06 ${hash})`,
                color: `oklch(0.35 0.09 ${hash})`,
            }}
            aria-hidden
        >
            {initials}
        </span>
    );
}
