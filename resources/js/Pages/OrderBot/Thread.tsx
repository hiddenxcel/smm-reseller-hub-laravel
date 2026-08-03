import { Link } from '@inertiajs/react';
import { ArrowLeft, Eye } from 'lucide-react';
import { Fragment } from 'react';
import { clockTime, dayLabel, initials } from './inbox-bits';
import { InboxThread } from './types';

/**
 * One conversation, oldest at the top.
 *
 * Inbound sits left, outbound right — the arrangement every chat app uses, so
 * who said what needs no legend. Day dividers break up a long thread, since
 * timestamps alone leave you counting back to work out when something happened.
 */
export function Thread({ thread }: { thread: InboxThread }) {
    const label = thread.name ?? thread.phone;

    return (
        <div className="flex max-h-[calc(100dvh-16rem)] flex-col rounded-xl border border-border bg-card">
            <header className="flex items-center gap-3 border-b border-border p-3">
                <Link
                    href={route('order-bot.inbox')}
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

            <div className="scroll-slim flex-1 space-y-1 overflow-y-auto p-3">
                {thread.messages.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        No messages in this conversation.
                    </p>
                ) : (
                    thread.messages.map((message, index) => {
                        const previous = thread.messages[index - 1];

                        // A divider goes in wherever the calendar day changes,
                        // including before the very first message.
                        const showDay =
                            message.at !== null &&
                            (previous?.at == null ||
                                dayLabel(previous.at) !== dayLabel(message.at));

                        return (
                            <Fragment key={message.id}>
                                {showDay && message.at && (
                                    <p className="py-2 text-center text-xs text-muted-foreground">
                                        {dayLabel(message.at)}
                                    </p>
                                )}

                                <div
                                    className={
                                        message.direction === 'out'
                                            ? 'flex justify-end'
                                            : 'flex justify-start'
                                    }
                                >
                                    <div
                                        className={[
                                            'max-w-[85%] rounded-2xl px-3 py-2 sm:max-w-[70%]',
                                            message.direction === 'out'
                                                ? 'bg-primary/10'
                                                : 'bg-muted',
                                        ].join(' ')}
                                    >
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

            {/* Where a reply box would be. Saying why is kinder than an input
                that silently does nothing. */}
            <footer className="flex items-center gap-2 border-t border-border p-3 text-xs text-muted-foreground">
                <Eye className="size-3.5 shrink-0" aria-hidden />
                The order bot handles these conversations itself. Replies are sent from
                the support bot.
            </footer>
        </div>
    );
}
