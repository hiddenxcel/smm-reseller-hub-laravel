import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { FormEvent } from 'react';
import { PriorityBadge, StatusBadge, messageTime } from './bits';

type Message = {
    id: number;
    author: string;
    author_label: string;
    body: string;
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
    };
    messages: Message[];
    canReply: boolean;
};

/**
 * One conversation with the platform.
 *
 * Laid out as a thread rather than a table of replies: this is a conversation,
 * and the shape people already read conversations in is a stack of messages
 * with the newest at the bottom.
 */
export default function SupportTicket({ ticket, messages, canReply }: Props) {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <Link
                        href={route('help.support')}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ChevronLeft className="size-4" />
                        Support Center
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
                    </p>
                </div>
            }
        >
            <Head title={`${ticket.reference} — Support`} />

            <div className="mx-auto max-w-3xl">
                <ul className="space-y-3">
                    {messages.map((message) => (
                        <li key={message.id}>
                            <MessageBubble message={message} />
                        </li>
                    ))}
                </ul>

                {canReply ? (
                    <ReplyForm ticketId={ticket.id} />
                ) : (
                    <p className="mt-6 rounded-xl border border-border bg-muted/40 p-4 text-center text-sm text-muted-foreground">
                        This ticket is closed. If the problem comes back, please open a
                        new one.
                    </p>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * Sides are distinguished by alignment and border rather than by two saturated
 * colours: a long support thread read top to bottom should not be a wall of
 * alternating blue and grey blocks.
 */
function MessageBubble({ message }: { message: Message }) {
    const fromUs = message.author === 'admin';

    return (
        <div className={fromUs ? '' : 'sm:pl-10'}>
            <div
                className={[
                    'rounded-xl border p-4',
                    fromUs
                        ? 'border-primary/30 bg-primary/5'
                        : 'border-border bg-card',
                ].join(' ')}
            >
                <div className="flex items-baseline justify-between gap-3">
                    <span className="text-sm font-semibold">{message.author_label}</span>
                    <span className="text-xs text-muted-foreground">
                        {messageTime(message.created_at)}
                    </span>
                </div>

                {/* Resellers paste error text and order ids in here, so the
                    original line breaks are the message. */}
                <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed">
                    {message.body}
                </p>
            </div>
        </div>
    );
}

function ReplyForm({ ticketId }: { ticketId: number }) {
    const { data, setData, post, processing, errors, reset } = useForm({ body: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('help.tickets.reply', ticketId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <form onSubmit={submit} className="mt-6">
            <label className="mb-1.5 block text-sm font-medium">Reply</label>

            <textarea
                value={data.body}
                onChange={(event) => setData('body', event.target.value)}
                rows={4}
                maxLength={5000}
                placeholder="Add anything else that might help us…"
                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                required
            />

            {errors.body && (
                <p className="mt-1 text-xs text-destructive">{errors.body}</p>
            )}

            <div className="mt-2 flex justify-end">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    {processing ? 'Sending…' : 'Send reply'}
                </button>
            </div>
        </form>
    );
}
