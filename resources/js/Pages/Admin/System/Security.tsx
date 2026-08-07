import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link, router, useForm } from '@inertiajs/react';
import { Ban, Eye } from 'lucide-react';
import { dateTime, Empty } from '../bits';
import {
    AdminAccessRow,
    AdminLoginRow,
    BlockedIpRow,
    ImpersonationRow,
    SecurityKpis,
} from '../types';

type Props = {
    kpis: SecurityKpis;
    blockedIps: BlockedIpRow[];
    adminAccess?: AdminAccessRow[];
    adminLogins?: AdminLoginRow[];
    impersonations?: ImpersonationRow[];
};

export default function Security({
    kpis,
    blockedIps,
    adminAccess,
    adminLogins,
    impersonations,
}: Props) {
    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Security</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Who has access, where from, and what they did with it.
                    </p>
                </div>
            }
        >
            <Head title="Security — Control" />

            <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
                <Tile label="Admins" value={`${kpis.activeAdmins}/${kpis.admins}`} />
                <Tile label="Blocked IPs" value={kpis.blockedIps} />
                <Tile label="Visits (7d)" value={kpis.impersonations7d} />
                <Tile
                    label="Open visits"
                    value={kpis.openImpersonations}
                    alert={kpis.openImpersonations > 0}
                />
                <Tile label="Admin actions (7d)" value={kpis.adminActions7d} />
            </div>

            <div className="mt-6 grid gap-4 lg:grid-cols-2">
                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Admin accounts</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        An account nobody has used in months is an account worth
                        asking about.
                    </p>

                    <Deferred data="adminAccess" fallback={<ListSkeleton />}>
                        {adminAccess && adminAccess.length > 0 ? (
                            <ul className="space-y-2 text-sm">
                                {adminAccess.map((admin) => (
                                    <li
                                        key={admin.id}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span className="min-w-0 flex-1 truncate">
                                            <span className="font-medium">
                                                {admin.username}
                                            </span>
                                            <span className="ml-1.5 text-xs capitalize text-muted-foreground">
                                                {admin.role}
                                            </span>
                                        </span>

                                        {admin.status !== 'active' && (
                                            <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground">
                                                disabled
                                            </span>
                                        )}

                                        <span
                                            className={[
                                                'shrink-0 text-xs',
                                                admin.dormant
                                                    ? 'text-amber-700 dark:text-amber-300'
                                                    : 'text-muted-foreground',
                                            ].join(' ')}
                                        >
                                            {admin.lastLoginAt
                                                ? dateTime(admin.lastLoginAt)
                                                : 'never signed in'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>No admins.</Empty>
                        )}
                    </Deferred>
                </section>

                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="font-heading font-bold">Recent admin sign-ins</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        A new address next to a familiar name is the thing to notice.
                    </p>

                    <Deferred data="adminLogins" fallback={<ListSkeleton />}>
                        {adminLogins && adminLogins.length > 0 ? (
                            <ul className="space-y-2 text-sm">
                                {adminLogins.map((login) => (
                                    <li
                                        key={login.id}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span className="min-w-0 flex-1 truncate font-medium">
                                            {login.admin ?? 'unknown'}
                                        </span>
                                        <span className="shrink-0 font-mono text-xs text-muted-foreground">
                                            {login.ip ?? '—'}
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {dateTime(login.at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>No sign-ins recorded.</Empty>
                        )}
                    </Deferred>
                </section>
            </div>

            <section className="mt-4 rounded-xl border border-border bg-card p-5">
                <h2 className="font-heading font-bold">Reseller account visits</h2>
                <p className="mb-4 text-sm text-muted-foreground">
                    Every read-only visit into a reseller's account, with the reason
                    given at the door.
                </p>

                <Deferred data="impersonations" fallback={<ListSkeleton />}>
                    {impersonations && impersonations.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[42rem] text-sm">
                                <thead>
                                    <tr className="border-b border-border text-left text-xs uppercase tracking-wider text-muted-foreground">
                                        <th className="px-2 py-2 font-semibold">Admin</th>
                                        <th className="px-2 py-2 font-semibold">Reseller</th>
                                        <th className="px-2 py-2 font-semibold">Reason</th>
                                        <th className="px-2 py-2 font-semibold">Started</th>
                                        <th className="px-2 py-2 font-semibold">Ended</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {impersonations.map((visit) => (
                                        <tr
                                            key={visit.id}
                                            className="border-b border-border last:border-0"
                                        >
                                            <td className="px-2 py-2 font-medium">
                                                {visit.admin}
                                            </td>
                                            <td className="px-2 py-2">
                                                <Link
                                                    href={route(
                                                        'admin.tenants.show',
                                                        visit.tenantId,
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    {visit.tenant}
                                                </Link>
                                            </td>
                                            <td className="px-2 py-2 text-muted-foreground">
                                                <span className="block max-w-[16rem] truncate">
                                                    {visit.reason ?? '—'}
                                                </span>
                                            </td>
                                            <td className="px-2 py-2 text-muted-foreground">
                                                {dateTime(visit.startedAt)}
                                            </td>
                                            <td className="px-2 py-2">
                                                {visit.open ? (
                                                    <span className="inline-flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-300">
                                                        <Eye className="size-3" />
                                                        open
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {dateTime(visit.endedAt)}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <Empty>Nobody has viewed a reseller account.</Empty>
                    )}
                </Deferred>
            </section>

            <BlockedIps rows={blockedIps} />
        </AdminLayout>
    );
}

function BlockedIps({ rows }: { rows: BlockedIpRow[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        ip: '',
        reason: '',
        expires_at: '',
    });

    return (
        <section className="mt-4 rounded-xl border border-border bg-card p-5">
            <h2 className="font-heading font-bold">Blocked addresses</h2>
            <p className="mb-4 text-sm text-muted-foreground">
                A manual list, not an automatic ban system — automatic blocking on
                failed logins is how a shared office IP locks out a paying reseller
                at 2am with nobody watching.
            </p>

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('admin.security.block'), {
                        onSuccess: () => reset(),
                    });
                }}
                className="flex flex-wrap items-start gap-2"
            >
                <div className="min-w-[10rem]">
                    <input
                        type="text"
                        value={data.ip}
                        onChange={(e) => setData('ip', e.target.value)}
                        placeholder="203.0.113.4"
                        className={inputClass}
                    />
                    {errors.ip && (
                        <p className="mt-1 text-xs text-destructive">{errors.ip}</p>
                    )}
                </div>

                <input
                    type="text"
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    placeholder="Reason (optional)"
                    className={`${inputClass} min-w-[14rem] flex-1`}
                />

                <div>
                    <input
                        type="datetime-local"
                        value={data.expires_at}
                        onChange={(e) => setData('expires_at', e.target.value)}
                        className={inputClass}
                        title="Leave empty for a permanent block"
                    />
                    {errors.expires_at && (
                        <p className="mt-1 text-xs text-destructive">
                            {errors.expires_at}
                        </p>
                    )}
                </div>

                <button
                    type="submit"
                    disabled={processing || data.ip.trim() === ''}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-destructive px-3 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    <Ban className="size-4" />
                    Block
                </button>
            </form>

            {rows.length === 0 ? (
                <Empty>Nothing is blocked.</Empty>
            ) : (
                <ul className="mt-4 space-y-2 text-sm">
                    {rows.map((row) => (
                        <li
                            key={row.id}
                            className="flex flex-wrap items-center gap-3 rounded-lg border border-border px-3 py-2"
                        >
                            <span className="font-mono font-medium">{row.ip}</span>

                            {row.expired && (
                                <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground">
                                    expired
                                </span>
                            )}

                            <span className="min-w-0 flex-1 truncate text-muted-foreground">
                                {row.reason ?? 'no reason given'}
                            </span>

                            <span className="text-xs text-muted-foreground">
                                {row.expiresAt
                                    ? `until ${dateTime(row.expiresAt)}`
                                    : 'permanent'}
                                {row.by && ` · by ${row.by}`}
                            </span>

                            <button
                                type="button"
                                onClick={() =>
                                    router.delete(
                                        route('admin.security.unblock', row.id),
                                    )
                                }
                                className="rounded-lg border border-border px-2 py-1 text-xs font-medium transition-colors hover:bg-accent"
                            >
                                Unblock
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

const inputClass =
    'rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

function Tile({
    label,
    value,
    alert = false,
}: {
    label: string;
    value: number | string;
    alert?: boolean;
}) {
    return (
        <div
            className={[
                'rounded-xl border bg-card p-4',
                alert ? 'border-amber-500/40' : 'border-border',
            ].join(' ')}
        >
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={[
                    'font-heading mt-1 text-xl font-extrabold tabular-nums',
                    alert ? 'text-amber-700 dark:text-amber-300' : '',
                ].join(' ')}
            >
                {typeof value === 'number' ? value.toLocaleString() : value}
            </p>
        </div>
    );
}

function ListSkeleton() {
    return (
        <div className="space-y-2">
            {[0, 1, 2].map((i) => (
                <div key={i} className="h-6 animate-pulse rounded bg-muted" />
            ))}
        </div>
    );
}
