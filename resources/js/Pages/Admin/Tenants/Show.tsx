import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Eye } from 'lucide-react';
import { useState } from 'react';
import { money, StatusChip } from '../bits';
import { Abilities, TenantOverview } from '../types';
import ActionsPanel from './ActionsPanel';
import TenantTabs from './TenantTabs';

type Props = {
    tenant: TenantOverview;
    tab: string;
    can: Abilities;
};

export default function TenantShow({ tenant, tab, can }: Props) {
    const [impersonating, setImpersonating] = useState(false);

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <Link
                            href={route('admin.tenants.index')}
                            className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="size-3.5" />
                            All tenants
                        </Link>

                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="font-heading truncate text-xl font-extrabold">
                                {tenant.name}
                            </h1>
                            <StatusChip status={tenant.status} />
                        </div>

                        <p className="mt-0.5 truncate text-sm text-muted-foreground">
                            {tenant.email}
                            {tenant.phone && ` · ${tenant.phone}`}
                        </p>
                    </div>

                    {can.impersonate && (
                        <button
                            type="button"
                            onClick={() => setImpersonating(true)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-amber-500/50 bg-amber-500/10 px-3 py-2 text-sm font-medium text-amber-700 transition-colors hover:bg-amber-500/20 dark:text-amber-300"
                        >
                            <Eye className="size-4" />
                            View as reseller
                        </button>
                    )}
                </div>
            }
        >
            <Head title={`${tenant.name} — Control`} />

            <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
                <div className="min-w-0">
                    <StatRow tenant={tenant} />
                    <TenantTabs tenantId={tenant.id} tab={tab} overview={tenant} />
                </div>

                <ActionsPanel tenant={tenant} can={can} />
            </div>

            {impersonating && (
                <ImpersonateDialog
                    tenant={tenant}
                    onClose={() => setImpersonating(false)}
                />
            )}
        </AdminLayout>
    );
}

function StatRow({ tenant }: { tenant: TenantOverview }) {
    const stats = [
        { label: 'Paid us', value: money(tenant.stats.revenue) },
        { label: 'Orders', value: tenant.stats.orders.toLocaleString() },
        { label: 'Customers', value: tenant.stats.customers.toLocaleString() },
        {
            label: 'Wallets held',
            value: money(tenant.stats.walletsHeld),
            // Customer money the reseller is holding. It is the figure that
            // makes a suspension consequential, so it is called out.
            hint: "Their customers' money",
        },
    ];

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {stats.map((stat) => (
                <div
                    key={stat.label}
                    className="rounded-xl border border-border bg-card p-4"
                >
                    <p className="text-xs text-muted-foreground">{stat.label}</p>
                    <p className="font-heading mt-1 text-lg font-extrabold tabular-nums">
                        {stat.value}
                    </p>
                    {stat.hint && (
                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                            {stat.hint}
                        </p>
                    )}
                </div>
            ))}
        </div>
    );
}

/**
 * Asks why before entering someone's account.
 *
 * The reason is the first thing wanted when a reseller queries the visit, and
 * asking at the door is the only moment the admin actually knows it. The dialog
 * also states the read-only rule plainly — an admin who expects to be able to
 * fix something should find that out here, not three clicks later.
 */
function ImpersonateDialog({
    tenant,
    onClose,
}: {
    tenant: TenantOverview;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const submit = () => {
        setSubmitting(true);

        router.post(
            route('admin.impersonate.start', tenant.id),
            { reason },
            { onFinish: () => setSubmitting(false) },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4">
            <div className="w-full max-w-md rounded-xl border border-border bg-card p-6">
                <h2 className="font-heading text-lg font-extrabold">
                    View as {tenant.name}
                </h2>

                <p className="mt-2 text-sm text-muted-foreground">
                    You will see their dashboard as they do. The session is{' '}
                    <span className="font-semibold text-foreground">read-only</span> —
                    nothing can be changed, and the visit is recorded.
                </p>

                <label
                    htmlFor="reason"
                    className="mt-4 block text-sm font-medium"
                >
                    Reason <span className="text-muted-foreground">(optional)</span>
                </label>
                <input
                    id="reason"
                    type="text"
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    placeholder="e.g. ticket #482 — orders not sending"
                    maxLength={255}
                    autoFocus
                    className="mt-1.5 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />

                <div className="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-border px-3 py-2 text-sm transition-colors hover:bg-accent"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={submitting}
                        className="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-slate-900 transition-colors hover:bg-amber-400 disabled:opacity-60"
                    >
                        {submitting ? 'Entering…' : 'Enter account'}
                    </button>
                </div>
            </div>
        </div>
    );
}
