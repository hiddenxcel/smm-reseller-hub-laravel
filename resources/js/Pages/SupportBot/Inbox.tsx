import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { inputClass } from '../OrderBot/bits';
import { initials, relativeTime } from '../OrderBot/inbox-bits';
import { SupportThread as Thread } from './Thread';
import { SupportConversation, SupportThread } from './types';

/**
 * The support inbox — where a person answers.
 *
 * Unlike the order bot's inbox this one can reply, because here there is a
 * defined moment when the bot stands down: a customer who picks "Talk to a
 * Human Agent" hands the conversation over, and the bot answers nothing until
 * staff hand it back. Conversations in that state are flagged in the list,
 * since each one is somebody waiting.
 *
 * Two panes on a wide screen, one at a time on a phone.
 */
export default function SupportBotInbox({
    conversations,
    q,
    phone,
    thread,
    canSend,
}: {
    conversations: SupportConversation[];
    q: string;
    phone: string | null;
    thread: SupportThread | null;
    canSend: boolean;
}) {
    const [query, setQuery] = useState(q);

    const waiting = conversations.filter((conversation) => conversation.awaitingHuman).length;

    const search = () =>
        router.get(
            route('support-bot.inbox'),
            query.trim() === '' ? {} : { q: query.trim() },
            { preserveState: true, replace: true },
        );

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-bold">Support inbox</h1>
                        <p className="text-sm text-muted-foreground">
                            Conversations with your support bot — and the ones it handed to you.
                        </p>
                    </div>

                    {waiting > 0 && (
                        <span className="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1.5 text-sm font-medium text-primary">
                            <span className="size-2 rounded-full bg-primary" aria-hidden />
                            {waiting} waiting for you
                        </span>
                    )}
                </div>
            }
        >
            <Head title="Inbox — Support Bot" />

            <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
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
                        <Thread thread={thread} canSend={canSend} />
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
    conversations: SupportConversation[];
    active: string | null;
    q: string;
}) {
    if (conversations.length === 0) {
        return (
            <p className="rounded-xl border border-border bg-card p-8 text-center text-sm text-muted-foreground">
                {q === ''
                    ? 'No conversations yet. Messages to your support bot will appear here.'
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
                            href={route('support-bot.inbox')}
                            data={
                                q === ''
                                    ? { phone: conversation.phone }
                                    : { phone: conversation.phone, q }
                            }
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
                                    <span className="truncate text-sm font-medium">{label}</span>
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

                                <span className="mt-1 flex flex-wrap gap-1">
                                    {conversation.awaitingHuman && (
                                        <span className="inline-block rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary">
                                            Waiting for you
                                        </span>
                                    )}
                                    {conversation.blocked && (
                                        <span className="inline-block rounded bg-destructive/10 px-1.5 py-0.5 text-[10px] font-medium text-destructive">
                                            Blocked
                                        </span>
                                    )}
                                </span>
                            </span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
