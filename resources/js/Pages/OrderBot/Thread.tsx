import { Link } from '@inertiajs/react';
import { ArrowLeft, Eye } from 'lucide-react';
import { Fragment, ReactNode, useEffect, useRef } from 'react';
import { Avatar, clockTime, dayLabel } from './inbox-bits';
import { InboxThread } from './types';

/**
 * One conversation, oldest at the top, opened at the newest message.
 *
 * Inbound sits left, outbound right — the arrangement every chat app uses, so
 * who said what needs no legend. Day pills break up a long thread, since
 * timestamps alone leave you counting back to work out when something
 * happened.
 */
export function Thread({ thread }: { thread: InboxThread }) {
    const label = thread.name ?? thread.phone;
    const end = useRef<HTMLDivElement>(null);

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

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <header className="flex items-center gap-3 border-b border-border bg-background px-3 py-3 sm:px-4">
                <Link
                    href={route('order-bot.inbox')}
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

            <div ref={scroller} onScroll={track} className="scroll-slim min-h-0 flex-1 overflow-y-auto bg-muted/30 px-3 py-4 sm:px-6">
                {thread.messages.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        No messages in this conversation.
                    </p>
                ) : (
                    <div className="mx-auto max-w-2xl space-y-1.5">
                        {thread.messages.map((message, index) => {
                            const previous = thread.messages[index - 1];
                            const outgoing = message.direction === 'out';

                            // A pill goes in wherever the calendar day changes,
                            // including before the very first message.
                            const showDay =
                                message.at !== null &&
                                (previous?.at == null ||
                                    dayLabel(previous.at) !== dayLabel(message.at));

                            // Consecutive messages from the same side sit closer
                            // together, and only the first of a run gets the
                            // squared "tail" corner.
                            const startsRun = previous?.direction !== message.direction || showDay;

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
                                            outgoing ? 'justify-end' : 'justify-start',
                                            startsRun ? 'pt-1.5' : '',
                                        ].join(' ')}
                                    >
                                        <div
                                            className={[
                                                'max-w-[85%] rounded-2xl px-3.5 py-2 shadow-sm sm:max-w-[75%]',
                                                outgoing
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'border border-border bg-card',
                                                startsRun
                                                    ? outgoing
                                                        ? 'rounded-tr-md'
                                                        : 'rounded-tl-md'
                                                    : '',
                                            ].join(' ')}
                                        >
                                            <p className="whitespace-pre-wrap break-words text-sm leading-relaxed">
                                                {formatChat(message.message)}
                                            </p>
                                            <p
                                                className={[
                                                    'mt-1 text-right text-[10px]',
                                                    outgoing
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

            {/* Where a reply box would be. Saying why is kinder than an input
                that silently does nothing. */}
            <footer className="flex items-center justify-center gap-2 border-t border-border bg-background px-4 py-2.5 text-xs text-muted-foreground">
                <Eye className="size-3.5 shrink-0" aria-hidden />
                Read only — the order bot answers these on its own.
            </footer>
        </div>
    );
}

/**
 * WhatsApp's own *bold* markup, shown as bold. The bot writes it for the
 * customer's phone; left as asterisks here it just looks like noise.
 */
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
