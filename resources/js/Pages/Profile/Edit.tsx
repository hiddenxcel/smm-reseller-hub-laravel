import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarDays, Gift, LogOut } from 'lucide-react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

type PlanLine = {
    name: string;
    state: 'active' | 'sandbox' | 'locked';
    daysLeft: number | null;
};

type Account = {
    memberSince: string | null;
    credit: number;
    plan: PlanLine[];
};

/** A steady colour per business, so the avatar is theirs and not a generic grey. */
function hueOf(label: string): number {
    let hash = 0;

    for (const char of label) {
        hash = (hash * 31 + char.charCodeAt(0)) % 360;
    }

    return hash;
}

function initialsOf(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    return (parts.length > 1 ? parts[0][0] + parts[1][0] : parts[0].slice(0, 2)).toUpperCase();
}

/**
 * The reseller's own account: who they are, what they hold, how to sign in.
 *
 * The top of the page answers "is this the right account, and is it in good
 * standing?" before any form appears. The three things that can be edited
 * follow, quietest last — deleting the account is a separate, plainly marked
 * block at the bottom, never next to a Save button.
 */
export default function Edit({ account }: { account: Account }) {
    const user = usePage().props.auth.user;

    const since = account.memberSince
        ? new Date(account.memberSince).toLocaleDateString('en-US', {
              month: 'long',
              year: 'numeric',
          })
        : null;

    const hue = hueOf(user.business_name);

    return (
        <AuthenticatedLayout>
            <Head title="Profile" />

            <div className="mx-auto max-w-3xl space-y-4 sm:space-y-6">
                <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
                    <div className="flex items-center gap-4">
                        <span
                            className="grid size-16 shrink-0 place-items-center rounded-full text-xl font-extrabold"
                            style={{
                                backgroundColor: `oklch(0.92 0.06 ${hue})`,
                                color: `oklch(0.35 0.09 ${hue})`,
                            }}
                            aria-hidden
                        >
                            {initialsOf(user.business_name)}
                        </span>

                        <div className="min-w-0 flex-1">
                            <h1 className="font-heading truncate text-xl font-extrabold tracking-tight sm:text-2xl">
                                {user.business_name}
                            </h1>
                            <p className="truncate text-sm text-muted-foreground">{user.email}</p>
                            {since && (
                                <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <CalendarDays className="size-3.5 shrink-0" aria-hidden />
                                    Member since {since}
                                </p>
                            )}
                        </div>
                    </div>

                    {/* Sign-out lives in the sidebar, which is behind a menu on a
                        phone — so it is also here, where someone looking at
                        their account would look for it. */}
                    <Button variant="outline" className="mt-4 w-full sm:w-auto" asChild>
                        <Link href={route('logout')} method="post" as="button">
                            <LogOut className="size-4" />
                            Sign out
                        </Link>
                    </Button>
                </section>

                <PlanCard account={account} />

                <Panel
                    title="Your details"
                    description="How we know you, and where we reach you."
                >
                    <UpdateProfileInformationForm />
                </Panel>

                <Panel title="Password" description="Use a long one you do not use anywhere else.">
                    <UpdatePasswordForm />
                </Panel>

                <DeleteUserForm />
            </div>
        </AuthenticatedLayout>
    );
}

function Panel({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <div className="mb-4">
                <h2 className="font-heading text-base font-bold">{title}</h2>
                {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
            </div>
            {children}
        </section>
    );
}

/** What the reseller holds, in a line each, with the way to change it. */
function PlanCard({ account }: { account: Account }) {
    return (
        <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <div className="mb-3 flex items-center justify-between gap-3">
                <h2 className="font-heading text-base font-bold">Your plan</h2>

                {account.credit > 0 && (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary">
                        <Gift className="size-3.5" aria-hidden />${account.credit.toFixed(2)} credit
                    </span>
                )}
            </div>

            <ul className="-mx-1 divide-y divide-border">
                {account.plan.map((line) => (
                    <li key={line.name} className="flex items-center justify-between gap-3 px-1 py-3">
                        <div className="min-w-0">
                            <p className="text-sm font-medium">{line.name}</p>
                            <p className="text-xs text-muted-foreground">{detail(line)}</p>
                        </div>
                        <StateBadge state={line.state} />
                    </li>
                ))}
            </ul>

            <Button variant="outline" className="mt-3 w-full sm:w-auto" asChild>
                <Link href={route('billing')}>Manage plan</Link>
            </Button>
        </section>
    );
}

function detail(line: PlanLine): string {
    const { state, daysLeft } = line;

    if (state === 'active' && daysLeft !== null) {
        return daysLeft > 0
            ? `${daysLeft} day${daysLeft === 1 ? '' : 's'} left`
            : 'Expires today';
    }

    if (state === 'sandbox') {
        return 'Test mode — answers your test numbers only';
    }

    if (daysLeft !== null && daysLeft < 0) {
        return `Expired ${Math.abs(daysLeft)} day${Math.abs(daysLeft) === 1 ? '' : 's'} ago`;
    }

    return 'Not subscribed';
}

function StateBadge({ state }: { state: PlanLine['state'] }) {
    const styles = {
        active: 'bg-primary/10 text-primary',
        sandbox:
            'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]',
        locked: 'bg-muted text-muted-foreground',
    }[state];

    const label = { active: 'Active', sandbox: 'Test mode', locked: 'Off' }[state];

    return (
        <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${styles}`}>
            {label}
        </span>
    );
}
