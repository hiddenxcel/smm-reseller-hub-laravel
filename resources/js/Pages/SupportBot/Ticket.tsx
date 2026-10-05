import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Bot, Send, UserCheck } from 'lucide-react';
import { Fragment, ReactNode } from 'react';
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
        <AuthenticatedLayout>
            <Head title={`Ticket #${ticket.id} — Support Bot`} />

            <div className="mx-auto max-w-3xl space-y-4">
                <header className="space-y-3">
                    <Link
                        href={route('support-bot.tickets')}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-3.5" aria-hidden />
                        All tickets
                    </Link>

                    <div>
                        <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                            {ticket.subject ?? 'Support request'}
                        </h1>
                        <p className="font-data mt-0.5 text-sm text-muted-foreground">
                            {ticket.customer ?? '—'}
                            {ticket.orderRef && ` · order #${ticket.orderRef}`}
                        </p>
                    </div>

                    {/* Status and priority side by side, each labelled — two
                        bare dropdowns do not say which is which. */}
                    <div className="grid grid-cols-2 gap-3">
                        <label className="block">
                            <span className="mb-1 block text-xs text-muted-foreground">Status</span>
                            <select
                                className="h-10 w-full rounded-xl border border-border bg-background px-3 text-sm"
                                value={ticket.status}
                                onChange={(event) => patch({ status: event.target.value })}
                            >
                                <option value="open">Open</option>
                                <option value="pending">Pending</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-xs text-muted-foreground">
                                Priority
                            </span>
                            <select
                                className="h-10 w-full rounded-xl border border-border bg-background px-3 text-sm"
                                value={ticket.priority}
                                onChange={(event) => patch({ priority: event.target.value })}
                            >
                                <option value="low">Low</option>
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                            </select>
                        </label>
                    </div>
                </header>

                <div className="flex flex-col overflow-hidden rounded-2xl border border-border bg-card">
                    {ticket.handedOver && (
                        <div className="flex items-center gap-3 border-b border-border bg-primary/5 px-4 py-2.5">
                            <UserCheck className="size-4 shrink-0 text-primary" aria-hidden />
                            <p className="min-w-0 flex-1 text-xs">
                                <span className="font-medium">You are answering.</span>{' '}
                                <span className="text-muted-foreground">The bot stays silent.</span>
                            </p>
                            <button
                                type="button"
                                onClick={() => patch({ handedOver: false })}
                                className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-input bg-background px-3 py-1.5 text-xs font-medium"
                            >
                                <Bot className="size-3.5" aria-hidden />
                                Hand back
                            </button>
                        </div>
                    )}

                    <div className="scroll-slim max-h-[calc(100dvh-26rem)] min-h-64 flex-1 overflow-y-auto bg-muted/30 px-3 py-4 sm:px-5">
                        {ticket.messages.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                Nothing has been said on this ticket yet.
                            </p>
                        ) : (
                            <div className="space-y-1.5">
                                {ticket.messages.map((message, index) => {
                                    const previous = ticket.messages[index - 1];
                                    const mine = message.sender !== 'customer';
                                    const staff = message.sender === 'staff';

                                    const showDay =
                                        message.at !== null &&
                                        (previous?.at == null ||
                                            dayLabel(previous.at) !== dayLabel(message.at));

                                    const startsRun =
                                        previous?.sender !== message.sender || showDay;

                                    return (
                                        <Fragment key={message.id}>
                                            {showDay && message.at && (
                                                <div className="flex justify-center py-2">
                                                    <span className="rounded-full border border-border bg-card px-3 py-1 text-xs text-muted-foreground shadow-sm">
                                                        {dayLabel(message.at)}
                                                    </span>
                                                </div>
                                            )}

                                            <div
                                                className={[
                                                    'flex',
                                                    mine ? 'justify-end' : 'justify-start',
                                                    startsRun ? 'pt-1.5' : '',
                                                ].join(' ')}
                                            >
                                                <div
                                                    className={[
                                                        'max-w-[85%] rounded-2xl px-3.5 py-2 shadow-sm sm:max-w-[75%]',
                                                        staff
                                                            ? 'bg-primary text-primary-foreground'
                                                            : mine
                                                              ? 'border border-primary/20 bg-primary/10'
                                                              : 'border border-border bg-card',
                                                        startsRun
                                                            ? mine
                                                                ? 'rounded-tr-md'
                                                                : 'rounded-tl-md'
                                                            : '',
                                                    ].join(' ')}
                                                >
                                                    {startsRun && (
                                                        <p
                                                            className={[
                                                                'mb-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                                                staff
                                                                    ? 'text-primary-foreground/70'
                                                                    : 'text-muted-foreground',
                                                            ].join(' ')}
                                                        >
                                                            {SENDER_LABELS[message.sender] ??
                                                                message.sender}
                                                        </p>
                                                    )}
                                                    <p className="whitespace-pre-wrap break-words text-sm leading-relaxed">
                                                        {formatChat(message.message)}
                                                    </p>
                                                    <p
                                                        className={[
                                                            'mt-1 text-right text-[10px]',
                                                            staff
                                                                ? 'text-primary-foreground/70'
                                                                : 'text-muted-foreground',
                                                        ].join(' ')}
                                                    >
                                                        {clockTime(message.at)}
                                                    </p>
                                                </div>
                                            </div>
                                        </Fragment>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    <footer className="border-t border-border px-3 py-3 sm:px-4">
                        {blocked !== null ? (
                            <p className="text-center text-xs text-muted-foreground">{blocked}</p>
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
                                        rows={1}
                                        maxLength={4000}
                                        placeholder="Write a reply…"
                                        aria-label="Reply to this ticket"
                                        className="scroll-slim max-h-32 min-h-11 flex-1 resize-none rounded-2xl border border-input bg-background px-4 py-2.5 text-sm focus:border-ring focus:outline-none focus:ring-[3px] focus:ring-ring/30"
                                    />

                                    <button
                                        type="button"
                                        onClick={send}
                                        disabled={
                                            form.processing || form.data.message.trim() === ''
                                        }
                                        className="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground disabled:opacity-50"
                                        aria-label={form.processing ? 'Sending' : 'Send reply'}
                                    >
                                        <Send className="size-4" aria-hidden />
                                    </button>
                                </div>

                                <p className="mt-2 text-center text-xs text-muted-foreground">
                                    Sent to the customer over WhatsApp.
                                    {!ticket.handedOver &&
                                        ' Replying also takes this conversation off the bot.'}
                                </p>
                            </>
                        )}
                    </footer>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/** WhatsApp's own *bold* markup, shown as bold rather than as asterisks. */
function formatChat(text: string | null): ReactNode {
    if (text === null) {
        return '—';
    }

    return text.split(/(\*[^*\n]+\*)/g).map((part, index) =>
        part.length > 2 && part.startsWith('*') && part.endsWith('*') ? (
            <strong key={index}>{part.slice(1, -1)}</strong>
        ) : (
            <Fragment key={index}>{part}</Fragment>
        ),
    );
}
