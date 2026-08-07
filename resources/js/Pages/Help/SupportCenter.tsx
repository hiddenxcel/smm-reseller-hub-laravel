import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Mail, MessageCircle, Plus, Ticket as TicketIcon } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { PriorityBadge, StatusBadge, whenLabel } from './bits';

type TicketRow = {
    id: number;
    reference: string;
    subject: string;
    category_label: string;
    priority: string;
    status: string;
    last_reply_by: string;
    last_reply_at: string | null;
    messages_count: number;
};

type Props = {
    tickets: TicketRow[];
    contacts: { email?: string; whatsapp?: string };
    categories: Record<string, string>;
    priorities: string[];
};

/**
 * Where a reseller asks the platform for help.
 *
 * The form is behind a button rather than always open: most visits here are to
 * check on something already asked, and a blank form at the top of the page
 * pushes that list below the fold.
 */
export default function SupportCenter({
    tickets,
    contacts,
    categories,
    priorities,
}: Props) {
    const [composing, setComposing] = useState(false);

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-extrabold">Support Center</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Ask us anything about your account, your bots or your billing.
                    </p>
                </div>
            }
        >
            <Head title="Support Center" />

            <div className="mx-auto max-w-4xl">
                <ContactStrip contacts={contacts} />

                <div className="mt-6 flex items-center justify-between gap-3">
                    <h2 className="font-heading text-base font-bold">Your tickets</h2>

                    {!composing && (
                        <button
                            type="button"
                            onClick={() => setComposing(true)}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                        >
                            <Plus className="size-4" />
                            New ticket
                        </button>
                    )}
                </div>

                {composing && (
                    <NewTicketForm
                        categories={categories}
                        priorities={priorities}
                        onCancel={() => setComposing(false)}
                    />
                )}

                {tickets.length === 0 ? (
                    <EmptyState onStart={() => setComposing(true)} composing={composing} />
                ) : (
                    <ul className="mt-4 space-y-2">
                        {tickets.map((ticket) => (
                            <li key={ticket.id}>
                                <TicketCard ticket={ticket} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * The other ways to reach us — but only the ones that are configured.
 *
 * Rendered above the ticket list because somebody whose bot is down at 2am
 * wants the WhatsApp number, not a form.
 */
function ContactStrip({ contacts }: { contacts: Props['contacts'] }) {
    const rows = [
        contacts.whatsapp && {
            icon: MessageCircle,
            label: 'WhatsApp',
            value: contacts.whatsapp,
            href: `https://wa.me/${contacts.whatsapp.replace(/[^\d]/g, '')}`,
        },
        contacts.email && {
            icon: Mail,
            label: 'Email',
            value: contacts.email,
            href: `mailto:${contacts.email}`,
        },
    ].filter(Boolean) as Array<{
        icon: typeof Mail;
        label: string;
        value: string;
        href: string;
    }>;

    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {rows.map((row) => {
                const Icon = row.icon;

                return (
                    <a
                        key={row.label}
                        href={row.href}
                        target="_blank"
                        rel="noreferrer"
                        className="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:bg-accent"
                    >
                        <Icon className="size-5 shrink-0 text-muted-foreground" />
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold">{row.label}</span>
                            <span className="block truncate text-sm text-muted-foreground">
                                {row.value}
                            </span>
                        </span>
                    </a>
                );
            })}
        </div>
    );
}

function NewTicketForm({
    categories,
    priorities,
    onCancel,
}: {
    categories: Record<string, string>;
    priorities: string[];
    onCancel: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        subject: '',
        category: Object.keys(categories)[0] ?? 'other',
        priority: 'normal',
        body: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('help.tickets.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="mt-4 space-y-4 rounded-xl border border-border bg-card p-4 sm:p-5"
        >
            <Field label="Subject" error={errors.subject}>
                <input
                    type="text"
                    value={data.subject}
                    onChange={(event) => setData('subject', event.target.value)}
                    maxLength={150}
                    placeholder="Orders are not going through"
                    className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                    required
                />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="What is it about?" error={errors.category}>
                    <select
                        value={data.category}
                        onChange={(event) => setData('category', event.target.value)}
                        className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                    >
                        {Object.entries(categories).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Priority" error={errors.priority}>
                    <select
                        value={data.priority}
                        onChange={(event) => setData('priority', event.target.value)}
                        className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm capitalize"
                    >
                        {priorities.map((priority) => (
                            <option key={priority} value={priority} className="capitalize">
                                {priority}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>

            <Field
                label="What is happening?"
                error={errors.body}
                hint="The more detail the better — order numbers, error messages, what you expected."
            >
                <textarea
                    value={data.body}
                    onChange={(event) => setData('body', event.target.value)}
                    rows={6}
                    maxLength={5000}
                    className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                    required
                />
            </Field>

            <div className="flex justify-end gap-2">
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
                    className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    {processing ? 'Sending…' : 'Send ticket'}
                </button>
            </div>
        </form>
    );
}

function Field({
    label,
    error,
    hint,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium">{label}</label>
            {children}
            {hint && !error && (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            )}
            {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
        </div>
    );
}

function TicketCard({ ticket }: { ticket: TicketRow }) {
    return (
        <Link
            href={route('help.tickets.show', ticket.id)}
            className="block rounded-xl border border-border bg-card p-4 transition-colors hover:bg-accent"
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-xs text-muted-foreground">
                    {ticket.reference}
                </span>
                <StatusBadge status={ticket.status} />
                <PriorityBadge priority={ticket.priority} />
            </div>

            <p className="mt-1.5 font-semibold">{ticket.subject}</p>

            <p className="mt-1 text-xs text-muted-foreground">
                {ticket.category_label} · {ticket.messages_count}{' '}
                {ticket.messages_count === 1 ? 'message' : 'messages'} · Last activity{' '}
                {whenLabel(ticket.last_reply_at)}
            </p>
        </Link>
    );
}

function EmptyState({
    onStart,
    composing,
}: {
    onStart: () => void;
    composing: boolean;
}) {
    return (
        <div className="mt-4 rounded-xl border border-dashed border-border py-12 text-center">
            <TicketIcon className="mx-auto size-8 text-muted-foreground/50" />
            <p className="mt-3 text-sm font-medium">No tickets yet</p>
            <p className="mt-1 text-sm text-muted-foreground">
                When something is not working, tell us here and we will pick it up.
            </p>

            {!composing && (
                <button
                    type="button"
                    onClick={onStart}
                    className="mt-4 rounded-lg border border-border px-3 py-2 text-sm font-medium transition-colors hover:bg-accent"
                >
                    Open your first ticket
                </button>
            )}
        </div>
    );
}
