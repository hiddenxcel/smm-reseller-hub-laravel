import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Bot, Send, UserCheck } from 'lucide-react';
import { Fragment } from 'react';
import { clockTime, dayLabel, initials } from '../OrderBot/inbox-bits';
import { SupportThread as SupportThreadData } from './types';

/**
 * One support conversation, oldest at the top — and the box to answer it.
 *
 * Three voices, not two, so the arrangement carries more than left/right. The
 * customer sits left; the bot and a staff reply both sit right but are
 * labelled, because "did a person already answer this?" is the first thing
 * somebody picking up the conversation needs to know, and it cannot be read
 * off the direction alone.
 */
export function SupportThread({
    thread,
    canSend,
}: {
    thread: SupportThreadData;
    canSend: boolean;
}) {
    const label = thread.name ?? thread.phone;

    const form = useForm({ phone: thread.phone, message: '' });

    const send = () => {
        if (form.data.message.trim() === '') {
            return;
        }

        form.post(route('support-bot.inbox.reply'), {
            preserveScroll: true,
            onSuccess: () => form.reset('message'),
        });
    };

    const returnToBot = () =>
        router.post(
            route('support-bot.inbox.return'),
            { phone: thread.phone },
            { preserveScroll: true },
        );

    // Three separate reasons the box is unusable, and a reseller can act on
    // each differently — so they are named rather than collapsed into one
    // disabled state with no explanation.
    const blocked = thread.blocked
        ? 'This customer is blocked. Unblock them to reply.'
        : !canSend
          ? 'No WhatsApp number is connected to send from.'
          : !thread.withinWindow
            ? 'WhatsApp only allows a free-form reply within 24 hours of the customer’s last message. Wait until they write in again.'
            : null;

    return (
        <div className="flex max-h-[calc(100dvh-16rem)] flex-col rounded-xl border border-border bg-card">
            <header className="flex items-center gap-3 border-b border-border p-3">
                <Link
                    href={route('support-bot.inbox')}
                    className="rounded-lg p-1 text-muted-foreground transition-colors hover:bg-accent lg:hidden"
                    aria-label="Back to conversations"
                >
                    <ArrowLeft className="size-4" />
                </Link>

                <span
                    className="grid size-9 shrink-0 place-items-center rounded-full bg-muted text-xs font-semibold"
                    aria-hidden
                >
                    {initials(label)}
                </span>

                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{label}</p>
                    {thread.name && (
                        <p className="font-data truncate text-xs text-muted-foreground">
                            {thread.phone}
                        </p>
                    )}
                </div>

                {thread.blocked && (
                    <span className="shrink-0 rounded bg-destructive/10 px-2 py-0.5 text-[11px] font-medium text-destructive">
                        Blocked
                    </span>
                )}

                {thread.balance !== null && (
                    <span className="font-data shrink-0 text-sm text-muted-foreground">
                        {thread.balance.toFixed(2)}
                    </span>
                )}
            </header>

            {thread.handedOver && (
                <div className="flex flex-wrap items-center gap-3 border-b border-border bg-primary/5 px-3 py-2">
                    <UserCheck className="size-4 shrink-0 text-primary" aria-hidden />
                    <p className="min-w-0 flex-1 text-xs">
                        <span className="font-medium">You are answering this customer.</span>{' '}
                        <span className="text-muted-foreground">
                            The bot stays silent until you hand it back.
                        </span>
                    </p>
                    <button
                        type="button"
                        onClick={returnToBot}
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-input bg-background px-2.5 py-1 text-xs font-medium"
                    >
                        <Bot className="size-3.5" aria-hidden />
                        Return to bot
                    </button>
                </div>
            )}

            <div className="scroll-slim flex-1 space-y-1 overflow-y-auto p-3">
                {thread.messages.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        No messages in this conversation.
                    </p>
                ) : (
                    thread.messages.map((message, index) => {
                        const previous = thread.messages[index - 1];

                        const showDay =
                            message.at !== null &&
                            (previous?.at == null ||
                                dayLabel(previous.at) !== dayLabel(message.at));

                        const mine = message.sender !== 'customer';

                        return (
                            <Fragment key={message.id}>
                                {showDay && message.at && (
                                    <p className="py-2 text-center text-xs text-muted-foreground">
                                        {dayLabel(message.at)}
                                    </p>
                                )}

                                <div className={mine ? 'flex justify-end' : 'flex justify-start'}>
                                    <div
                                        className={[
                                            'max-w-[85%] rounded-2xl px-3 py-2 sm:max-w-[70%]',
                                            message.sender === 'staff'
                                                ? 'bg-primary/15'
                                                : message.sender === 'bot'
                                                  ? 'bg-primary/5'
                                                  : 'bg-muted',
                                        ].join(' ')}
                                    >
                                        {mine && (
                                            <p className="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                                {message.sender === 'staff' ? 'You' : 'Bot'}
                                            </p>
                                        )}
                                        <p className="whitespace-pre-wrap break-words text-sm">
                                            {message.message ?? '—'}
                                        </p>
                                        <p className="mt-0.5 text-right text-[10px] text-muted-foreground">
                                            {clockTime(message.at)}
                                        </p>
                                    </div>
                                </div>
                            </Fragment>
                        );
                    })
                )}
            </div>

            <footer className="border-t border-border p-3">
                {blocked !== null ? (
                    <p className="text-xs text-muted-foreground">{blocked}</p>
                ) : (
                    <>
                        <div className="flex items-end gap-2">
                            <textarea
                                value={form.data.message}
                                onChange={(event) => form.setData('message', event.target.value)}
                                onKeyDown={(event) => {
                                    // Enter sends, Shift+Enter breaks the line —
                                    // this is a chat box, and a reply is usually
                                    // one line.
                                    if (event.key === 'Enter' && !event.shiftKey) {
                                        event.preventDefault();
                                        send();
                                    }
                                }}
                                rows={2}
                                maxLength={4000}
                                placeholder="Write a reply…"
                                aria-label="Reply to this customer"
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

                        {!thread.handedOver && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Replying takes this conversation off the bot until you hand it
                                back.
                            </p>
                        )}
                    </>
                )}
            </footer>
        </div>
    );
}
