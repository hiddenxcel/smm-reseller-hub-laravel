import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronLeft, Lock } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { PriorityBadge, StatusBadge, messageTime } from './bits';

type Message = {
    id: number;
    author: string;
    author_label: string;
    body: string;
    internal: boolean;
    created_at: string | null;
};

type Props = {
    ticket: {
        id: number;
        reference: string;
        subject: string;
        category_label: string;
        priority: string;
        status: string;
        created_at: string | null;
        tenant: { id: number; business_name: string; email: string } | null;
    };
    messages: Message[];
    canManage: boolean;
    priorities: string[];
};

export default function SupportShow({
    ticket,
    messages,
    canManage,
    priorities,
}: Props) {
    return (
        <AdminLayout
            header={
                <div>
                    <Link
                        href={route('admin.support.index')}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ChevronLeft className="size-4" />
                        Help desk
                    </Link>

                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <span className="font-mono text-xs text-muted-foreground">
                            {ticket.reference}
                        </span>
                        <StatusBadge status={ticket.status} />
                        <PriorityBadge priority={ticket.priority} />
                    </div>

                    <h1 className="font-heading mt-1 text-xl font-extrabold">
                        {ticket.subject}
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        {ticket.category_label}
                        {ticket.tenant && (
                            <>
                                {' · '}
                                <Link
                                    href={route('admin.tenants.show', ticket.tenant.id)}
                                    className="hover:underline"
                                >
                                    {ticket.tenant.business_name}
                                </Link>{' '}
                                <span className="text-muted-foreground/70">
                                    ({ticket.tenant.email})
                                </span>
                            </>
                        )}
                    </p>
                </div>
            }
        >
            <Head title={`${ticket.reference} — Help desk`} />

            <div className="mx-auto max-w-3xl">
                {canManage && (
                    <ActionBar ticket={ticket} priorities={priorities} />
                )}

                <ul className="mt-4 space-y-3">
                    {messages.map((message) => (
                        <li key={message.id}>
                            <MessageBubble message={message} />
                        </li>
                    ))}
                </ul>

                {canManage && <ReplyForm ticketId={ticket.id} />}
            </div>
        </AdminLayout>
    );
}

function ActionBar({
    ticket,
    priorities,
}: {
    ticket: Props['ticket'];
    priorities: string[];
}) {
    const act = (action: string, data: Record<string, string> = {}) => {
        router.post(route('admin.support.act', [ticket.id, action]), data, {
            preserveScroll: true,
        });
    };

    const settled = ticket.status === 'resolved' || ticket.status === 'closed';

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card p-3">
            <select
                value={ticket.priority}
                onChange={(event) => act('priority', { priority: event.target.value })}
                className="rounded-lg border border-border bg-background px-2 py-1.5 text-sm capitalize"
                aria-label="Priority"
            >
                {priorities.map((priority) => (
                    <option key={priority} value={priority} className="capitalize">
                        {priority}
                    </option>
                ))}
            </select>

            <div className="flex-1" />

            {settled ? (
                <button
                    type="button"
                    onClick={() => act('reopen')}
                    className="rounded-lg border border-border px-3 py-1.5 text-sm transition-colors hover:bg-accent"
                >
                    Reopen
                </button>
            ) : (
                <button
                    type="button"
                    onClick={() => act('resolve')}
                    className="rounded-lg border border-border px-3 py-1.5 text-sm transition-colors hover:bg-accent"
                >
                    Mark resolved
                </button>
            )}

            {ticket.status !== 'closed' && (
                <button
                    type="button"
                    onClick={() => act('close')}
                    className="rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent"
                    title="The reseller will no longer be able to reply"
                >
                    Close
                </button>
            )}
        </div>
    );
}

function MessageBubble({ message }: { message: Message }) {
    // A note must never be mistaken for something the reseller can read, so it
    // gets the one visual treatment nothing else on the page uses.
    if (message.internal) {
        return (
            <div className="rounded-xl border border-dashed border-amber-500/50 bg-amber-500/5 p-4">
                <div className="flex items-baseline justify-between gap-3">
                    <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-amber-700 dark:text-amber-300">
                        <Lock className="size-3.5" />
                        {message.author_label} · internal note
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {messageTime(message.created_at)}
                    </span>
                </div>
                <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed">
                    {message.body}
                </p>
            </div>
        );
    }

    const fromAdmin = message.author === 'admin';

    return (
        <div className={fromAdmin ? 'sm:pl-10' : ''}>
            <div
                className={[
                    'rounded-xl border p-4',
                    fromAdmin ? 'border-primary/30 bg-primary/5' : 'border-border bg-card',
                ].join(' ')}
            >
                <div className="flex items-baseline justify-between gap-3">
                    <span className="text-sm font-semibold">{message.author_label}</span>
                    <span className="text-xs text-muted-foreground">
                        {messageTime(message.created_at)}
                    </span>
                </div>
                <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed">
                    {message.body}
                </p>
            </div>
        </div>
    );
}

/**
 * One box, two destinations.
 *
 * The internal toggle is beside the send button rather than a separate form:
 * the mistake worth preventing is writing a note into a reply, and that is
 * likeliest when the two are far apart on the screen.
 */
function ReplyForm({ ticketId }: { ticketId: number }) {
    const [internal, setInternal] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        body: '',
        internal: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('admin.support.reply', ticketId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setInternal(false);
            },
        });
    };

    const toggle = (value: boolean) => {
        setInternal(value);
        setData('internal', value);
    };

    return (
        <form onSubmit={submit} className="mt-6">
            <textarea
                value={data.body}
                onChange={(event) => setData('body', event.target.value)}
                rows={5}
                maxLength={5000}
                placeholder={
                    internal
                        ? 'A note for other admins. The reseller will not see this.'
                        : 'Your reply to the reseller…'
                }
                className={[
                    'w-full rounded-lg border bg-background px-3 py-2 text-sm',
                    internal ? 'border-amber-500/50' : 'border-border',
                ].join(' ')}
                required
            />

            {errors.body && (
                <p className="mt-1 text-xs text-destructive">{errors.body}</p>
            )}

            <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
                <label className="inline-flex items-center gap-2 text-sm text-muted-foreground">
                    <input
                        type="checkbox"
                        checked={internal}
                        onChange={(event) => toggle(event.target.checked)}
                        className="rounded border-border"
                    />
                    Internal note — not sent to the reseller
                </label>

                <button
                    type="submit"
                    disabled={processing}
                    className={[
                        'rounded-lg px-4 py-2 text-sm font-semibold transition-opacity hover:opacity-90 disabled:opacity-50',
                        internal
                            ? 'bg-amber-600 text-white'
                            : 'bg-primary text-primary-foreground',
                    ].join(' ')}
                >
                    {processing ? 'Saving…' : internal ? 'Save note' : 'Send reply'}
                </button>
            </div>
        </form>
    );
}
