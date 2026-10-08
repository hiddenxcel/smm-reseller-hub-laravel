import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Bot, Send, UserCheck } from 'lucide-react';
import { Fragment, ReactNode, useEffect, useRef } from 'react';
import { Avatar, clockTime, dayLabel } from '../OrderBot/inbox-bits';
import { SupportThread as SupportThreadData } from './types';

/**
 * One support conversation, oldest at the top — and the box to answer it.
 *
 * Three voices, not two, so the arrangement carries more than left/right. The
 * customer sits left; the bot and a staff reply both sit right but are told
 * apart — the bot is the quiet tint, your own reply the solid one, and each is
 * labelled — because "did a person already answer this?" is the first thing
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
    const end = useRef<HTMLDivElement>(null);

    const form = useForm({ phone: thread.phone, message: '' });

    // A chat is read from the bottom. A new message pulls the reader down only
    // if they were already at the bottom: someone scrolled up to read what was
    // said earlier must not be thrown to the end by every live update.
    const scroller = useRef<HTMLDivElement>(null);
    const atBottom = useRef(true);

    const track = () => {
        const el = scroller.current;

        if (el) {
            atBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
        }
    };

    useEffect(() => {
        atBottom.current = true;
        end.current?.scrollIntoView({ block: 'end' });
    }, [thread.phone]);

    useEffect(() => {
        if (atBottom.current) {
            end.current?.scrollIntoView({ block: 'end' });
        }
    }, [thread.messages.length]);

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
        <div className="flex min-h-0 flex-1 flex-col">
            <header className="flex items-center gap-3 border-b border-border bg-background px-3 py-3 sm:px-4">
                <Link
                    href={route('support-bot.inbox')}
                    className="rounded-full p-2 text-muted-foreground transition-colors hover:bg-accent lg:hidden"
                    aria-label="Back to conversations"
                >
                    <ArrowLeft className="size-5" />
                </Link>

                <Avatar label={label} />

                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{label}</p>
                    {thread.name && (
                        <p className="font-data truncate text-xs text-muted-foreground">
                            {thread.phone}
                        </p>
                    )}
                </div>

                {thread.blocked && (
                    <span className="shrink-0 rounded-full bg-destructive/10 px-2.5 py-0.5 text-xs font-medium text-destructive">
                        Blocked
                    </span>
                )}

                {thread.balance !== null && (
                    <span className="shrink-0 rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground">
                        Wallet{' '}
                        <span className="font-data font-semibold text-foreground">
                            {thread.balance.toFixed(2)}
                        </span>
                    </span>
                )}
            </header>

            {thread.handedOver && (
                <div className="flex items-center gap-3 border-b border-border bg-primary/5 px-3 py-2 sm:px-4">
                    <UserCheck className="size-4 shrink-0 text-primary" aria-hidden />
                    <p className="min-w-0 flex-1 text-xs">
                        <span className="font-medium">You are answering.</span>{' '}
                        <span className="text-muted-foreground">The bot stays silent.</span>
                    </p>
                    <button
                        type="button"
                        onClick={returnToBot}
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-input bg-background px-3 py-1.5 text-xs font-medium"
                    >
                        <Bot className="size-3.5" aria-hidden />
                        Hand back
                    </button>
                </div>
            )}

            <div ref={scroller} onScroll={track} className="scroll-slim min-h-0 flex-1 overflow-y-auto bg-muted/30 px-3 py-4 sm:px-6">
                {thread.messages.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        No messages in this conversation.
                    </p>
                ) : (
                    <div className="mx-auto max-w-2xl space-y-1.5">
                        {thread.messages.map((message, index) => {
                            const previous = thread.messages[index - 1];
                            const mine = message.sender !== 'customer';
                            const staff = message.sender === 'staff';

                            const showDay =
                                message.at !== null &&
                                (previous?.at == null ||
                                    dayLabel(previous.at) !== dayLabel(message.at));

                            const startsRun = previous?.sender !== message.sender || showDay;

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
                                            {mine && startsRun && (
                                                <p
                                                    className={[
                                                        'mb-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                                        staff
                                                            ? 'text-primary-foreground/70'
                                                            : 'text-muted-foreground',
                                                    ].join(' ')}
                                                >
                                                    {staff ? 'You' : 'Bot'}
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

                <div ref={end} />
            </div>

            <footer className="border-t border-border bg-background px-3 py-3 sm:px-4">
                {blocked !== null ? (
                    <p className="text-center text-xs text-muted-foreground">{blocked}</p>
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
                                rows={1}
                                maxLength={4000}
                                placeholder="Write a reply…"
                                aria-label="Reply to this customer"
                                className="scroll-slim max-h-32 min-h-11 flex-1 resize-none rounded-2xl border border-input bg-background px-4 py-2.5 text-sm focus:border-ring focus:outline-none focus:ring-[3px] focus:ring-ring/30"
                            />

                            <button
                                type="button"
                                onClick={send}
                                disabled={form.processing || form.data.message.trim() === ''}
                                className="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground disabled:opacity-50"
                                aria-label={form.processing ? 'Sending' : 'Send reply'}
                            >
                                <Send className="size-4" aria-hidden />
                            </button>
                        </div>

                        {!thread.handedOver && (
                            <p className="mt-2 text-center text-xs text-muted-foreground">
                                Replying takes this conversation off the bot until you hand it back.
                            </p>
                        )}
                    </>
                )}
            </footer>
        </div>
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
