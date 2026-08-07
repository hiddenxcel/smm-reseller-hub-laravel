import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Eye, Search } from 'lucide-react';
import { useState } from 'react';
import { Thread } from './Thread';
import { initials, relativeTime } from './inbox-bits';
import { inputClass } from './bits';
import { Conversation, InboxThread } from './types';

/**
 * What customers said to the order bot, and what it said back.
 *
 * Read-only by design: the bot is mid-conversation with these people, and a
 * reply typed here would have them answering two voices at once. Support is
 * where a human takes over, and it has its own inbox.
 *
 * Two panes on a wide screen, one at a time on a phone — opening a
 * conversation swaps the list out rather than squeezing both in.
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

    const search = () =>
        router.get(
            route('order-bot.inbox'),
            query.trim() === '' ? {} : { q: query.trim() },
            { preserveState: true, replace: true },
        );

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-bold">Inbox</h1>
                        <p className="text-sm text-muted-foreground">
                            Conversations with your order bot.
                        </p>
                    </div>

                    <span className="inline-flex items-center gap-2 rounded-full bg-muted px-3 py-1.5 text-sm text-muted-foreground">
                        <Eye className="size-3.5" aria-hidden />
                        Read only
                    </span>
                </div>
            }
        >
            <Head title="Inbox — Order Bot" />

            <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
                {/* On a phone the list hides once a thread is open. */}
                <div className={thread ? 'hidden lg:block' : ''}>
                    <div className="relative mb-3">
                        <Search
                            className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={(event) => event.key === 'Enter' && search()}
                            placeholder="Search name, number or message"
                            aria-label="Search conversations"
                            className={`${inputClass} pl-9`}
                        />
                    </div>

                    <ConversationList conversations={conversations} active={phone} q={q} />
                </div>

                <div className={thread ? '' : 'hidden lg:block'}>
                    {thread ? (
                        <Thread thread={thread} />
                    ) : (
                        <p className="rounded-xl border border-border bg-card p-8 text-center text-sm text-muted-foreground">
                            Pick a conversation to read it.
                        </p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
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
            <p className="rounded-xl border border-border bg-card p-8 text-center text-sm text-muted-foreground">
                {q === ''
                    ? 'No conversations yet. Messages to your bot will appear here.'
                    : 'Nothing matches that search.'}
            </p>
        );
    }

    return (
        <ul className="scroll-slim max-h-[calc(100dvh-16rem)] divide-y divide-border overflow-y-auto rounded-xl border border-border bg-card">
            {conversations.map((conversation) => {
                const isActive = conversation.phone === active;
                const label = conversation.name ?? conversation.phone;

                return (
                    <li key={conversation.phone}>
                        <Link
                            href={route('order-bot.inbox')}
                            data={q === '' ? { phone: conversation.phone } : { phone: conversation.phone, q }}
                            preserveState
                            className={[
                                'flex gap-3 p-3 transition-colors',
                                isActive ? 'bg-accent' : 'hover:bg-accent/60',
                            ].join(' ')}
                            aria-current={isActive ? 'true' : undefined}
                        >
                            <span
                                className="grid size-9 shrink-0 place-items-center rounded-full bg-muted text-xs font-semibold"
                                aria-hidden
                            >
                                {initials(label)}
                            </span>

                            <span className="min-w-0 flex-1">
                                <span className="flex items-baseline justify-between gap-2">
                                    <span className="truncate text-sm font-medium">
                                        {label}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {relativeTime(conversation.lastAt)}
                                    </span>
                                </span>

                                <span className="mt-0.5 flex items-center gap-1.5">
                                    {conversation.lastDirection === 'out' && (
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            You:
                                        </span>
                                    )}
                                    <span className="truncate text-xs text-muted-foreground">
                                        {conversation.lastMessage ?? '—'}
                                    </span>
                                </span>

                                {conversation.blocked && (
                                    <span className="mt-1 inline-block rounded bg-destructive/10 px-1.5 py-0.5 text-[10px] font-medium text-destructive">
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
