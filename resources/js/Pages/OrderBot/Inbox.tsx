import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Eye, MessageSquareText, Search, X } from 'lucide-react';
import { useState } from 'react';
import { Thread } from './Thread';
import { Avatar, relativeTime } from './inbox-bits';
import { Conversation, InboxThread } from './types';

/**
 * What customers said to the order bot, and what it said back.
 *
 * Read-only by design: the bot is mid-conversation with these people, and a
 * reply typed here would have them answering two voices at once. Support is
 * where a human takes over, and it has its own inbox.
 *
 * Built like a messenger rather than a page with two boxes on it: the list
 * and the thread each fill the screen's height and scroll on their own, so
 * the header and the search never move. On a phone they take turns — opening
 * a conversation replaces the list, and the back arrow returns to it.
 */
export default function OrderBotInbox({
    conversations,
    q,
    phone,
    thread,
}: {
    conversations: Conversation[];
    q: string;
    phone: string | null;
    thread: InboxThread | null;
}) {
    const [query, setQuery] = useState(q);

    const search = (value: string) =>
        router.get(
            route('order-bot.inbox'),
            value.trim() === '' ? {} : { q: value.trim() },
            { preserveState: true, replace: true },
        );

    return (
        <AuthenticatedLayout bleed>
            <Head title="Inbox — Order Bot" />

            {/* The mobile bar above is about 3.4rem tall; the rest is ours. */}
            <div className="flex h-[calc(100dvh-3.4rem)] lg:h-dvh">
                <aside
                    className={[
                        'min-w-0 flex-col border-r border-border lg:flex lg:w-[22rem] lg:shrink-0',
                        thread ? 'hidden' : 'flex w-full',
                    ].join(' ')}
                >
                    <div className="space-y-3 px-4 pb-3 pt-5">
                        <div className="flex items-center justify-between gap-3">
                            <h1 className="font-heading text-xl font-extrabold tracking-tight">
                                Inbox
                            </h1>

                            <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground">
                                <Eye className="size-3.5" aria-hidden />
                                Read only
                            </span>
                        </div>

                        <div className="relative">
                            <Search
                                className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <input
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                onKeyDown={(event) => event.key === 'Enter' && search(query)}
                                placeholder="Search name, number or message"
                                aria-label="Search conversations"
                                className="h-10 w-full rounded-xl border border-border bg-background pl-9 pr-9 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                            />
                            {query !== '' && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setQuery('');
                                        search('');
                                    }}
                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-full p-1 text-muted-foreground hover:text-foreground"
                                    aria-label="Clear search"
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </div>
                    </div>

                    <ConversationList conversations={conversations} active={phone} q={q} />
                </aside>

                <section
                    className={['min-w-0 flex-1 flex-col lg:flex', thread ? 'flex' : 'hidden'].join(
                        ' ',
                    )}
                >
                    {thread ? <Thread thread={thread} /> : <NothingOpen />}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function NothingOpen() {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-3 bg-muted/30 p-8 text-center">
            <span className="grid size-14 place-items-center rounded-full bg-card shadow-sm">
                <MessageSquareText className="size-6 text-muted-foreground" aria-hidden />
            </span>
            <div>
                <p className="font-heading font-bold">Pick a conversation</p>
                <p className="mt-0.5 text-sm text-muted-foreground">
                    Choose someone on the left to read what they asked your bot.
                </p>
            </div>
        </div>
    );
}

function ConversationList({
    conversations,
    active,
    q,
}: {
    conversations: Conversation[];
    active: string | null;
    q: string;
}) {
    if (conversations.length === 0) {
        return (
            <p className="px-6 py-12 text-center text-sm text-muted-foreground">
                {q === ''
                    ? 'No conversations yet. Messages to your bot will appear here.'
                    : 'Nothing matches that search.'}
            </p>
        );
    }

    return (
        <ul className="scroll-slim min-h-0 flex-1 overflow-y-auto border-t border-border">
            {conversations.map((conversation) => {
                const isActive = conversation.phone === active;
                const label = conversation.name ?? conversation.phone;

                return (
                    <li key={conversation.phone} className="border-b border-border/60">
                        <Link
                            href={route('order-bot.inbox')}
                            data={
                                q === ''
                                    ? { phone: conversation.phone }
                                    : { phone: conversation.phone, q }
                            }
                            preserveState
                            className={[
                                'flex items-center gap-3 px-4 py-3 transition-colors',
                                isActive ? 'bg-accent' : 'hover:bg-accent/50',
                            ].join(' ')}
                            aria-current={isActive ? 'true' : undefined}
                        >
                            <Avatar label={label} />

                            <span className="min-w-0 flex-1">
                                <span className="flex items-baseline justify-between gap-2">
                                    <span className="truncate text-sm font-semibold">{label}</span>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {relativeTime(conversation.lastAt)}
                                    </span>
                                </span>

                                <span className="mt-0.5 flex items-center gap-1.5">
                                    {conversation.lastDirection === 'out' && (
                                        <span className="shrink-0 text-xs text-primary">Bot:</span>
                                    )}
                                    <span className="truncate text-sm text-muted-foreground">
                                        {firstLine(conversation.lastMessage)}
                                    </span>
                                </span>

                                {conversation.blocked && (
                                    <span className="mt-1 inline-block rounded-full bg-destructive/10 px-2 py-0.5 text-[10px] font-medium text-destructive">
                                        Blocked
                                    </span>
                                )}
                            </span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

/** A preview is one line: a menu or a receipt would otherwise sprawl. */
function firstLine(message: string | null): string {
    if (message === null) {
        return '—';
    }

    const line = message
        .split('\n')
        .map((part) => part.trim())
        .find((part) => part !== '');

    return (line ?? '—').replace(/\*/g, '');
}
