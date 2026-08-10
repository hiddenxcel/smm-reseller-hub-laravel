import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, MessageCircle } from 'lucide-react';

type Message = {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    answeredBy: string | null;
    at: string | null;
};

type Props = {
    conversation: {
        id: number;
        page: string | null;
        locale: string;
        startedAt: string | null;
        lead: { name: string | null; phone: string | null; message: string | null } | null;
        messages: Message[];
    };
};

/**
 * One visitor's chat, read back.
 *
 * Worth having as its own screen because a question in isolation is often
 * ambiguous — "and the other one?" means nothing without the turn before it —
 * and because seeing where an answer went wrong is what tells you which
 * knowledge row to write.
 */
export default function AssistantConversation({ conversation }: Props) {
    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <Link
                            href={route('admin.assistant.index')}
                            className="mb-1 inline-flex items-center gap-1 text-xs text-muted-foreground underline-offset-4 hover:underline"
                        >
                            <ArrowLeft className="size-3" />
                            Back to the assistant
                        </Link>

                        <h1 className="font-heading text-xl font-extrabold">
                            Conversation #{conversation.id}
                        </h1>

                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {conversation.startedAt}
                            {conversation.page && ` · opened on ${conversation.page}`}
                            {conversation.locale === 'sw' && ' · Kiswahili'}
                        </p>
                    </div>
                </div>
            }
        >
            <Head title={`Conversation #${conversation.id} — Control`} />

            {conversation.lead && (
                <div className="mb-4 rounded-xl border border-border bg-card px-4 py-3">
                    <p className="text-xs text-muted-foreground">Asked for a person</p>
                    <p className="mt-1 font-medium">{conversation.lead.name}</p>

                    <a
                        href={`https://wa.me/${(conversation.lead.phone ?? '').replace(/\D/g, '')}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-1 inline-flex items-center gap-1.5 rounded-full bg-[#25D366] px-2.5 py-1 text-xs font-semibold text-white"
                    >
                        <MessageCircle className="size-3" />
                        {conversation.lead.phone}
                    </a>

                    {conversation.lead.message && (
                        <p className="mt-2 text-sm text-muted-foreground">
                            {conversation.lead.message}
                        </p>
                    )}
                </div>
            )}

            <div className="space-y-3 rounded-xl border border-border bg-card px-4 py-4">
                {conversation.messages.map((message) => (
                    <div
                        key={message.id}
                        className={[
                            'flex',
                            message.role === 'user' ? 'justify-end' : 'justify-start',
                        ].join(' ')}
                    >
                        <div className="max-w-[80%]">
                            <div
                                className={[
                                    'rounded-2xl px-4 py-2.5 text-sm leading-relaxed whitespace-pre-line',
                                    message.role === 'user'
                                        ? 'rounded-br-md bg-primary text-primary-foreground'
                                        : 'rounded-bl-md bg-muted',
                                ].join(' ')}
                            >
                                {message.content}
                            </div>

                            <p
                                className={[
                                    'mt-1 flex items-center gap-1.5 text-xs text-muted-foreground',
                                    message.role === 'user' ? 'justify-end' : '',
                                ].join(' ')}
                            >
                                {message.at}

                                {/* How the reply was reached, which is the
                                    thing this screen is read for. */}
                                {message.answeredBy === 'knowledge' && <span>· written answer</span>}
                                {message.answeredBy === 'ai' && <span>· AI</span>}
                                {message.answeredBy === 'fallback' && (
                                    <span className="text-destructive">· could not answer</span>
                                )}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
        </AdminLayout>
    );
}
