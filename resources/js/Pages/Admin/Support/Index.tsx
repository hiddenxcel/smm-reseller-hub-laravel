import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';
import { DataTable, Empty, FilterTabs } from '../bits';
import { PriorityBadge, StatusBadge, waitingLabel, whenLabel } from './bits';

type Row = {
    id: number;
    reference: string;
    subject: string;
    category_label: string;
    priority: string;
    status: string;
    last_reply_at: string | null;
    messages_count: number;
    awaiting_us: boolean;
    waiting_hours: number | null;
    tenant: { id: number; business_name: string; email: string } | null;
};

type Props = {
    tickets: Row[];
    counts: Record<string, number>;
    filters: { status: string | null };
};

/**
 * The help desk queue: resellers waiting on us.
 *
 * Sorted by the server so that anything unanswered floats to the top whatever
 * the filter — the age of a ticket nobody has replied to is the one number that
 * turns into a churned reseller if it is left alone.
 */
export default function SupportIndex({ tickets, counts, filters }: Props) {
    const waiting = (counts.open ?? 0) + (counts.answered ?? 0);

    const select = (status: string | null) => {
        router.get(
            route('admin.support.index'),
            status ? { status } : {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Help desk</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {waiting === 0
                            ? 'Nothing is waiting on a reply.'
                            : `${waiting} ${waiting === 1 ? 'ticket is' : 'tickets are'} waiting on a reply.`}
                    </p>
                </div>
            }
        >
            <Head title="Help desk" />

            <FilterTabs
                tabs={[
                    { key: null, label: 'All' },
                    { key: 'open', label: 'New', count: counts.open },
                    { key: 'answered', label: 'Replied to us', count: counts.answered },
                    { key: 'pending', label: 'Waiting on them', count: counts.pending },
                    { key: 'resolved', label: 'Resolved', count: counts.resolved },
                    { key: 'closed', label: 'Closed', count: counts.closed },
                ]}
                current={filters.status}
                onSelect={select}
            />

            {tickets.length === 0 ? (
                <Empty>No tickets here.</Empty>
            ) : (
                <DataTable
                    headers={[
                        { label: 'Ticket' },
                        { label: 'Reseller' },
                        { label: 'Category' },
                        { label: 'Priority' },
                        { label: 'Status' },
                        { label: 'Waiting', align: 'right' },
                    ]}
                >
                    {tickets.map((ticket) => (
                        <tr
                            key={ticket.id}
                            className="border-b border-border last:border-0 hover:bg-accent/50"
                        >
                            <td className="px-4 py-3">
                                <Link
                                    href={route('admin.support.show', ticket.id)}
                                    className="block"
                                >
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {ticket.reference}
                                    </span>
                                    <span className="block font-medium">
                                        {ticket.subject}
                                    </span>
                                </Link>
                            </td>

                            <td className="px-4 py-3">
                                {ticket.tenant ? (
                                    <Link
                                        href={route('admin.tenants.show', ticket.tenant.id)}
                                        className="hover:underline"
                                    >
                                        {ticket.tenant.business_name}
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground">—</span>
                                )}
                            </td>

                            <td className="px-4 py-3 text-muted-foreground">
                                {ticket.category_label}
                            </td>

                            <td className="px-4 py-3">
                                <PriorityBadge priority={ticket.priority} />
                            </td>

                            <td className="px-4 py-3">
                                <StatusBadge status={ticket.status} />
                            </td>

                            <td className="px-4 py-3 text-right">
                                {ticket.awaiting_us ? (
                                    <span
                                        className={
                                            (ticket.waiting_hours ?? 0) >= 24
                                                ? 'font-semibold text-destructive'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {waitingLabel(ticket.waiting_hours)}
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">
                                        {whenLabel(ticket.last_reply_at)}
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </DataTable>
            )}
        </AdminLayout>
    );
}
