import AdminLayout from '@/Layouts/AdminLayout';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { Download, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Empty,
    FilterTabs,
    money,
    Pagination,
    pushFilters,
    ServiceChips,
    shortDate,
    StatusChip,
    TabsSkeleton,
} from '../bits';
import { Abilities, PageMeta, TenantFilters, TenantRow } from '../types';

type Props = {
    tenants: { data: TenantRow[]; meta: PageMeta };
    filters: TenantFilters;
    isFiltered: boolean;
    tabCounts?: { all: number; active: number; suspended: number };
    pageSizes: number[];
    serviceKeys: string[];
    can: Abilities;
};

const SERVICE_LABELS: Record<string, string> = {
    order_bot: 'Order Bot',
    support_bot: 'Support Bot',
    ai_tickets: 'AI Tickets',
    ai_chat: 'AI Chat',
    number_rental: 'Number Rental',
};

export default function TenantsIndex({
    tenants,
    filters,
    isFiltered,
    tabCounts,
    serviceKeys,
}: Props) {
    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-extrabold">Tenants</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Every reseller on the platform.
                        </p>
                    </div>

                    <a
                        href={route('admin.tenants.export', filters as never)}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-medium transition-colors hover:bg-accent"
                    >
                        <Download className="size-4" />
                        Export CSV
                    </a>
                </div>
            }
        >
            <Head title="Tenants — Control" />

            <FilterBar filters={filters} isFiltered={isFiltered} serviceKeys={serviceKeys} />

            <Deferred data="tabCounts" fallback={<TabsSkeleton />}>
                <FilterTabs
                    current={filters.status}
                    onSelect={(status) => visit({ ...filters, status })}
                    tabs={[
                        { key: null, label: 'All', count: tabCounts?.all },
                        { key: 'active', label: 'Active', count: tabCounts?.active },
                        {
                            key: 'suspended',
                            label: 'Suspended',
                            count: tabCounts?.suspended,
                        },
                    ]}
                />
            </Deferred>

            <div className="mt-4 overflow-x-auto rounded-xl border border-border bg-card">
                <table className="w-full min-w-[52rem] text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs uppercase tracking-wider text-muted-foreground">
                            <th className="px-4 py-3 font-semibold">Reseller</th>
                            <th className="px-4 py-3 font-semibold">Status</th>
                            <th className="px-4 py-3 font-semibold">Services</th>
                            <th className="px-4 py-3 text-right font-semibold">Paid us</th>
                            <th className="px-4 py-3 text-right font-semibold">Orders</th>
                            <th className="px-4 py-3 text-right font-semibold">Credit</th>
                            <th className="px-4 py-3 font-semibold">Joined</th>
                        </tr>
                    </thead>

                    <tbody>
                        {tenants.data.map((tenant) => (
                            <tr
                                key={tenant.id}
                                className="border-b border-border last:border-0 hover:bg-accent/50"
                            >
                                <td className="px-4 py-3">
                                    <Link
                                        href={route('admin.tenants.show', tenant.id)}
                                        className="block min-w-0"
                                    >
                                        <span className="block truncate font-medium hover:underline">
                                            {tenant.name}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {tenant.email}
                                        </span>
                                    </Link>
                                </td>
                                <td className="px-4 py-3">
                                    <StatusChip status={tenant.status} />
                                    {!tenant.hasPaid && (
                                        <span className="mt-1 block text-[10px] uppercase tracking-wide text-muted-foreground">
                                            Never paid
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    <ServiceChips services={tenant.services} />
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums">
                                    {money(tenant.revenue)}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                    {tenant.orders.toLocaleString()}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                    {money(tenant.credit)}
                                </td>
                                <td className="px-4 py-3 text-muted-foreground">
                                    {shortDate(tenant.joinedAt)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {tenants.data.length === 0 && (
                    <Empty>
                        {isFiltered
                            ? 'No resellers match these filters.'
                            : 'No resellers have signed up yet.'}
                    </Empty>
                )}
            </div>

            <Pagination
                meta={tenants.meta}
                onPage={(page) => visit(filters, page)}
            />
        </AdminLayout>
    );
}

/**
 * Search is debounced and pushed into the URL, like the reseller-facing tables:
 * a filtered view stays a shareable link rather than throwaway React state.
 */
function FilterBar({
    filters,
    isFiltered,
    serviceKeys,
}: {
    filters: TenantFilters;
    isFiltered: boolean;
    serviceKeys: string[];
}) {
    const [search, setSearch] = useState(filters.q ?? '');

    useEffect(() => {
        if (search === (filters.q ?? '')) {
            return;
        }

        const timer = setTimeout(() => {
            visit({ ...filters, q: search || null });
        }, 300);

        return () => clearTimeout(timer);
    }, [search]);

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-[16rem] flex-1">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    type="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search name, email, phone or referral code"
                    className="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
            </div>

            <select
                value={filters.service ?? ''}
                onChange={(e) => visit({ ...filters, service: e.target.value || null })}
                className="rounded-lg border border-border bg-background px-3 py-2 text-sm"
            >
                <option value="">Any service</option>
                {serviceKeys.map((key) => (
                    <option key={key} value={key}>
                        {SERVICE_LABELS[key] ?? key}
                    </option>
                ))}
            </select>

            <select
                value={filters.billing ?? ''}
                onChange={(e) => visit({ ...filters, billing: e.target.value || null })}
                className="rounded-lg border border-border bg-background px-3 py-2 text-sm"
            >
                <option value="">Any billing</option>
                <option value="paying">Paying</option>
                <option value="trial">Trial</option>
                <option value="never_paid">Never paid</option>
            </select>

            {isFiltered && (
                <button
                    type="button"
                    onClick={() => router.get(route('admin.tenants.index'))}
                    className="inline-flex items-center gap-1 rounded-lg border border-border px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent"
                >
                    <X className="size-3.5" />
                    Clear
                </button>
            )}
        </div>
    );
}

/** Push filter state into the URL, via the shared helper. */
function visit(filters: Partial<TenantFilters>, page?: number): void {
    pushFilters('admin.tenants.index', filters, page);
}
