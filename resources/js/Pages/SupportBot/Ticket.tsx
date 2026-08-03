import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Bot, Send, UserCheck } from 'lucide-react';
import { clockTime, dayLabel } from '../OrderBot/inbox-bits';
import { TicketDetail } from './types';

const SENDER_LABELS: Record<string, string> = {
    customer: 'Customer',
    ai: 'Bot',
    staff: 'You',
};

/**
 * One ticket, with its thread and the box to answer it.
 *
 * The reply goes out over WhatsApp, not into a portal the customer would have
 * to log into — they asked for help in a chat and that is where the answer
 * belongs. Which is also why the 24-hour window applies here exactly as it
 * does in the inbox.
 *
 * Status and handoff are separate controls on purpose: resolving a ticket
 * usually does not mean the bot should start talking again, and handing back
 * to the bot does not mean the question was answered.
 */
export default function SupportBotTicket({ ticket }: { ticket: TicketDetail }) {
    const form = useForm({ message: '' });

    const send = () => {
        if (form.data.message.trim() === '') {
            return;
        }

        form.post(route('support-bot.tickets.reply', ticket.id), {
            preserveScroll: true,
            onSuccess: () => form.reset('message'),
        });
    };

    const patch = (data: Record<string, string | boolean>) =>
        router.patch(route('support-bot.tickets.update', ticket.id), data, {
            preserveScroll: true,
        });

    const blocked = !ticket.canSend
        ? 'No WhatsApp number is connected to send from.'
        : !ticket.withinWindow
          ? 'WhatsApp only allows a free-form reply within 24 hours of the customer’s last message. Wait until they write in again.'
          : null;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <Link
                            href={route('support-bot.tickets')}
                            className="mb-1 inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <ArrowLeft className="size-3.5" aria-hidden />
                            All tickets
                        </Link>
                        <h1 className="font-heading truncate text-xl font-bold">
                            {ticket.subject ?? 'Support request'}
                        </h1>
                        <p className="font-data mt-0.5 text-sm text-muted-foreground">
                            {ticket.customer ?? '—'}
                            {ticket.orderRef && ` · order #${ticket.orderRef}`}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            className="rounded-lg border border-input bg-background px-3 py-1.5 text-sm"
                            value={ticket.status}
                            onChange={(event) => patch({ status: event.target.value })}
                            aria-label="Ticket status"
                        >
                            <option value="open">Open</option>
                            <option value="pending">Pending</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>

                        <select
                            className="rounded-lg border border-input bg-background px-3 py-1.5 text-sm"
                            value={ticket.priority}
                            onChange={(event) => patch({ priority: event.target.value })}
                            aria-label="Ticket priority"
                        >
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                </div>
            }
        >
            <Head title={`Ticket #${ticket.id} — Support Bot`} />

            <div className="mx-auto flex max-w-3xl flex-col rounded-xl border border-border bg-card">
                {ticket.handedOver && (
                    <div className="flex flex-wrap items-center gap-3 border-b border-border bg-primary/5 px-4 py-2.5">
                        <UserCheck className="size-4 shrink-0 text-primary" aria-hidden />
                        <p className="min-w-0 flex-1 text-xs">
                            <span className="font-medium">You are answering this customer.</span>{' '}
                            <span className="text-muted-foreground">
                                The bot stays silent until you hand it back.
                            </span>
                        </p>
                        <button
                            type="button"
                            onClick={() => patch({ handedOver: false })}
                            className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-input bg-background px-2.5 py-1 text-xs font-medium"
                        >
                            <Bot className="size-3.5" aria-hidden />
                            Return to bot
                        </button>
                    </div>
                )}

                <div className="scroll-slim max-h-[calc(100dvh-24rem)] flex-1 space-y-3 overflow-y-auto p-4">
                    {ticket.messages.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            Nothing has been said on this ticket yet.
                        </p>
                    ) : (
                        ticket.messages.map((message, index) => {
                            const previous = ticket.messages[index - 1];
                            const mine = message.sender !== 'customer';

                            const showDay =
                                message.at !== null &&
                                (previous?.at == null ||
                                    dayLabel(previous.at) !== dayLabel(message.at));

                            return (
                                <div key={message.id}>
                                    {showDay && message.at && (
                                        <p className="pb-2 text-center text-xs text-muted-foreground">
                                            {dayLabel(message.at)}
                                        </p>
                                    )}

                                    <div className={mine ? 'flex justify-end' : 'flex justify-start'}>
                                        <div
                                            className={[
                                                'max-w-[85%] rounded-2xl px-3 py-2 sm:max-w-[70%]',
                                                message.sender === 'staff'
                                                    ? 'bg-primary/15'
                                                    : message.sender === 'ai'
                                                      ? 'bg-primary/5'
                                                      : 'bg-muted',
                                            ].join(' ')}
                                        >
                                            <p className="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                                {SENDER_LABELS[message.sender] ?? message.sender}
                                            </p>
                                            <p className="whitespace-pre-wrap break-words text-sm">
                                                {message.message}
                                            </p>
                                            <p className="mt-0.5 text-right text-[10px] text-muted-foreground">
                                                {clockTime(message.at)}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>

                <footer className="border-t border-border p-4">
                    {blocked !== null ? (
                        <p className="text-xs text-muted-foreground">{blocked}</p>
                    ) : (
                        <>
                            <div className="flex items-end gap-2">
                                <textarea
                                    value={form.data.message}
                                    onChange={(event) =>
                                        form.setData('message', event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' && !event.shiftKey) {
                                            event.preventDefault();
                                            send();
                                        }
                                    }}
                                    rows={2}
                                    maxLength={4000}
                                    placeholder="Write a reply…"
                                    aria-label="Reply to this ticket"
                                    className="scroll-slim max-h-32 min-h-11 flex-1 resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                                />

                                <button
                                    type="button"
                                    onClick={send}
                                    disabled={form.processing || form.data.message.trim() === ''}
                                    className="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-60"
                                >
                                    <Send className="size-4" aria-hidden />
                                    {form.processing ? 'Sending…' : 'Send'}
                                </button>
                            </div>

                            <p className="mt-2 text-xs text-muted-foreground">
                                Sent to the customer over WhatsApp.
                                {!ticket.handedOver &&
                                    ' Replying also takes this conversation off the bot.'}
                            </p>
                        </>
                    )}
                </footer>
            </div>
        </AuthenticatedLayout>
    );
}
