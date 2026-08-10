import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    MessageSquare,
    Pencil,
    Plus,
    Sparkles,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { Empty } from '../bits';

type KnowledgeRow = {
    id: number;
    topic: string;
    topicLabel: string;
    question: string;
    question_sw: string | null;
    answer: string;
    answer_sw: string | null;
    cta_label: string | null;
    cta_url: string | null;
    keywords: string | null;
    status: string;
    sort_order: number;
};

type AskedRow = {
    question: string;
    answeredBy: string | null;
    count: number;
    conversationId: number;
};

type LeadRow = {
    id: number;
    name: string | null;
    phone: string | null;
    message: string | null;
    page: string | null;
    at: string | null;
};

type KeyState = {
    /** env | database | stored-disabled | none */
    source: string;
    hint: string | null;
    stored: boolean;
    active: boolean;
};

type Props = {
    stats: {
        days: number;
        conversations: number;
        today: number;
        fromKnowledge: number;
        fromAi: number;
        unanswered: number;
        escalated: number;
        leads: number;
    };
    topQuestions: AskedRow[];
    unanswered: AskedRow[];
    leads: LeadRow[];
    knowledge: KnowledgeRow[];
    topics: Record<string, string>;
    enabled: boolean;
    apiKey: KeyState;
    canManage: boolean;
    canManageKey: boolean;
};

/** A blank row, and the shape the form posts. */
const EMPTY = {
    topic: 'order_bot',
    question: '',
    question_sw: '',
    answer: '',
    answer_sw: '',
    cta_label: '',
    cta_url: '',
    keywords: '',
    status: 'active',
    sort_order: 0,
};

export default function AssistantIndex({
    stats,
    topQuestions,
    unanswered,
    leads,
    knowledge,
    topics,
    enabled,
    apiKey,
    canManage,
    canManageKey,
}: Props) {
    const [tab, setTab] = useState<'activity' | 'knowledge'>('activity');
    const [editing, setEditing] = useState<KnowledgeRow | null>(null);
    const [drafting, setDrafting] = useState<string | null>(null);

    /**
     * Writing an answer to a question that was asked and missed.
     *
     * This is the loop the whole screen exists for: the visitor's own wording
     * becomes the question, and the next person to ask it gets a written
     * answer instead of a shrug.
     */
    function answerThis(question: string) {
        setEditing(null);
        setDrafting(question);
        setTab('knowledge');
    }

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-extrabold">AI Assistant</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            The chat widget on the public site — what it was asked, and
                            what it is allowed to say.
                        </p>
                    </div>

                    {canManage && (
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(null);
                                setDrafting('');
                                setTab('knowledge');
                            }}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                        >
                            <Plus className="size-4" />
                            Add answer
                        </button>
                    )}
                </div>
            }
        >
            <Head title="AI Assistant — Control" />

            <KeyPanel state={apiKey} enabled={enabled} canManage={canManageKey} />

            <div className="mb-5 flex gap-1 rounded-lg bg-muted/50 p-1">
                <Tab active={tab === 'activity'} onClick={() => setTab('activity')} icon={MessageSquare}>
                    Conversations
                </Tab>
                <Tab active={tab === 'knowledge'} onClick={() => setTab('knowledge')} icon={BookOpen}>
                    Knowledge base
                    <span className="ml-1.5 rounded-full bg-background px-1.5 text-xs">
                        {knowledge.length}
                    </span>
                </Tab>
            </div>

            {tab === 'activity' ? (
                <Activity
                    stats={stats}
                    topQuestions={topQuestions}
                    unanswered={unanswered}
                    leads={leads}
                    canManage={canManage}
                    onAnswer={answerThis}
                />
            ) : (
                <Knowledge
                    rows={knowledge}
                    topics={topics}
                    canManage={canManage}
                    onEdit={(row) => {
                        setDrafting(null);
                        setEditing(row);
                    }}
                />
            )}

            {(editing !== null || drafting !== null) && (
                <Editor
                    row={editing}
                    question={drafting ?? ''}
                    topics={topics}
                    onClose={() => {
                        setEditing(null);
                        setDrafting(null);
                    }}
                />
            )}
        </AdminLayout>
    );
}

/**
 * The DeepSeek key behind the widget.
 *
 * Here rather than only in .env because editing .env needs SSH, which in
 * practice means the key is never set and the whole feature stays off. The
 * value is written and never read back: the screen shows whether one is in
 * place and its last four characters, so an owner can tell which key is
 * loaded without it being recoverable from a screenshot.
 */
function KeyPanel({
    state,
    enabled,
    canManage,
}: {
    state: KeyState;
    enabled: boolean;
    canManage: boolean;
}) {
    const [editing, setEditing] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        key: '',
        enabled: state.active || !state.stored,
    });

    // An .env value beats anything typed here. Saying so is the difference
    // between an owner understanding the precedence and concluding the save
    // silently failed.
    const fromEnv = state.source === 'env';

    function submit(event: React.FormEvent) {
        event.preventDefault();

        post(route('admin.assistant.key'), {
            preserveScroll: true,
            onSuccess: () => {
                reset('key');
                setEditing(false);
            },
        });
    }

    if (!editing) {
        return (
            <div
                className={[
                    'mb-5 flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3 text-sm',
                    enabled ? 'border-border bg-card' : 'border-border bg-muted/40',
                ].join(' ')}
            >
                {enabled ? (
                    <Sparkles className="size-4 shrink-0 text-primary" />
                ) : (
                    <AlertTriangle className="size-4 shrink-0 text-muted-foreground" />
                )}

                <div className="min-w-0 flex-1">
                    <p className="font-semibold">
                        {enabled ? 'The widget is live' : 'The widget is hidden'}
                    </p>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {enabled ? (
                            <>
                                DeepSeek key {state.hint} in use, from{' '}
                                {fromEnv ? '.env' : 'this screen'}.
                            </>
                        ) : state.stored ? (
                            'A key is stored but switched off. Nothing renders on the public pages until you turn it on.'
                        ) : (
                            'No DeepSeek key yet. A chat that cannot answer reads as a broken product, so the widget does not render at all without one.'
                        )}
                    </p>
                </div>

                {canManage && (
                    <button
                        type="button"
                        onClick={() => setEditing(true)}
                        className="shrink-0 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                    >
                        {state.stored ? 'Change key' : 'Add key'}
                    </button>
                )}
            </div>
        );
    }

    return (
        <form
            onSubmit={submit}
            className="mb-5 space-y-3 rounded-xl border border-border bg-card px-4 py-4"
        >
            <div>
                <p className="text-sm font-bold">DeepSeek key</p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    From platform.deepseek.com → API keys. It is stored encrypted and
                    never shown again — only its last four characters.
                </p>
            </div>

            {fromEnv && (
                <p className="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                    <strong className="font-semibold text-foreground">
                        PLATFORM_DEEPSEEK_KEY is set in .env
                    </strong>{' '}
                    and wins over anything saved here. Remove it from .env if you want
                    this screen to take over.
                </p>
            )}

            <label className="block">
                <span className="mb-1.5 block text-xs font-medium text-muted-foreground">
                    Key
                    {state.stored && (
                        <span className="ml-1 font-normal">
                            (leave blank to keep {state.hint})
                        </span>
                    )}
                </span>

                <input
                    type="password"
                    autoComplete="off"
                    value={data.key}
                    placeholder="sk-…"
                    onChange={(event) => setData('key', event.target.value)}
                    className="w-full rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                />

                {errors.key && (
                    <span className="mt-1 block text-xs text-destructive">{errors.key}</span>
                )}
            </label>

            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={data.enabled}
                    onChange={(event) => setData('enabled', event.target.checked)}
                    className="size-4 rounded border-border"
                />
                Use this key — the widget appears on the public pages
            </label>

            <div className="flex justify-end gap-2">
                <button
                    type="button"
                    onClick={() => {
                        reset('key');
                        setEditing(false);
                    }}
                    className="rounded-lg border border-border px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    disabled={processing || (!state.stored && data.key.trim() === '')}
                    className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    Save key
                </button>
            </div>
        </form>
    );
}

function Tab({
    active,
    onClick,
    icon: Icon,
    children,
}: {
    active: boolean;
    onClick: () => void;
    icon: typeof BookOpen;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={[
                'flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                active
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
            ].join(' ')}
        >
            <Icon className="size-4" />
            {children}
        </button>
    );
}

function Activity({
    stats,
    topQuestions,
    unanswered,
    leads,
    canManage,
    onAnswer,
}: {
    stats: Props['stats'];
    topQuestions: AskedRow[];
    unanswered: AskedRow[];
    leads: LeadRow[];
    canManage: boolean;
    onAnswer: (question: string) => void;
}) {
    const answered = stats.fromKnowledge + stats.fromAi;
    const total = answered + stats.unanswered;

    return (
        <div className="space-y-5">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Conversations" value={stats.conversations} note={`last ${stats.days} days`} />
                <Stat
                    label="Answered outright"
                    value={stats.fromKnowledge}
                    note="from written answers — free"
                />
                <Stat label="Answered by AI" value={stats.fromAi} note="cost a model call" />
                <Stat
                    label="Could not answer"
                    value={stats.unanswered}
                    note={total > 0 ? `${Math.round((stats.unanswered / total) * 100)}% of questions` : 'nothing asked yet'}
                    alarming={stats.unanswered > 0}
                />
            </div>

            {/* The most valuable list on the screen: each row is a knowledge
                entry that does not exist yet, written by somebody who wanted
                it. */}
            <Panel
                title="Questions it could not answer"
                subtitle="Each of these is an answer worth writing. The next person to ask gets it instantly."
            >
                {unanswered.length === 0 ? (
                    <Empty>Nothing went unanswered. That is the number to keep at zero.</Empty>
                ) : (
                    <ul className="divide-y divide-border">
                        {unanswered.map((row) => (
                            <li
                                key={row.question}
                                className="flex items-center gap-3 py-2.5 text-sm"
                            >
                                <span className="flex-1 first-letter:uppercase">{row.question}</span>

                                {row.count > 1 && (
                                    <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                        asked {row.count}×
                                    </span>
                                )}

                                <Link
                                    href={route('admin.assistant.conversations.show', row.conversationId)}
                                    className="shrink-0 text-xs text-muted-foreground underline-offset-4 hover:underline"
                                >
                                    See chat
                                </Link>

                                {canManage && (
                                    <button
                                        type="button"
                                        onClick={() => onAnswer(row.question)}
                                        className="shrink-0 rounded-lg bg-primary px-2.5 py-1 text-xs font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                                    >
                                        Write answer
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel title="What people ask most" subtitle={`Across the last ${stats.days} days.`}>
                {topQuestions.length === 0 ? (
                    <Empty>Nobody has asked anything yet.</Empty>
                ) : (
                    <ul className="divide-y divide-border">
                        {topQuestions.map((row) => (
                            <li
                                key={`${row.question}-${row.answeredBy}`}
                                className="flex items-center gap-3 py-2.5 text-sm"
                            >
                                <span className="flex-1 first-letter:uppercase">{row.question}</span>
                                <Outcome value={row.answeredBy} />
                                <span className="w-10 shrink-0 text-right text-xs text-muted-foreground">
                                    {row.count}×
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel
                title="Asked for a person"
                subtitle="Left a name and a number inside the chat."
            >
                {leads.length === 0 ? (
                    <Empty>No callbacks requested.</Empty>
                ) : (
                    <ul className="divide-y divide-border">
                        {leads.map((lead) => (
                            <li key={lead.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                                <span className="font-medium">{lead.name}</span>
                                <a
                                    href={`https://wa.me/${(lead.phone ?? '').replace(/\D/g, '')}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-primary underline-offset-4 hover:underline"
                                >
                                    {lead.phone}
                                </a>

                                {lead.message && (
                                    <span className="w-full text-xs text-muted-foreground sm:w-auto sm:flex-1">
                                        {lead.message}
                                    </span>
                                )}

                                <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                    {lead.at}
                                </span>

                                <Link
                                    href={route('admin.assistant.conversations.show', lead.id)}
                                    className="shrink-0 text-xs text-muted-foreground underline-offset-4 hover:underline"
                                >
                                    See chat
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </div>
    );
}

function Knowledge({
    rows,
    topics,
    canManage,
    onEdit,
}: {
    rows: KnowledgeRow[];
    topics: Record<string, string>;
    canManage: boolean;
    onEdit: (row: KnowledgeRow) => void;
}) {
    if (rows.length === 0) {
        return <Empty>No answers written yet. The assistant cannot say anything about the product.</Empty>;
    }

    const grouped = Object.entries(topics)
        .map(([key, label]) => ({ key, label, items: rows.filter((row) => row.topic === key) }))
        .filter((group) => group.items.length > 0);

    return (
        <div className="space-y-5">
            <p className="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                Prices are never written here — they are read from the plan list on
                every question, so an answer cannot go stale. Say what a service{' '}
                <em>is</em>; let the price look after itself.
            </p>

            {grouped.map((group) => (
                <Panel key={group.key} title={group.label}>
                    <ul className="divide-y divide-border">
                        {group.items.map((row) => (
                            <li key={row.id} className="flex items-start gap-3 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium">
                                        {row.question}
                                        {row.status !== 'active' && (
                                            <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs font-normal text-muted-foreground">
                                                hidden
                                            </span>
                                        )}
                                    </p>
                                    <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                                        {row.answer}
                                    </p>

                                    <div className="mt-1.5 flex flex-wrap gap-2 text-xs text-muted-foreground">
                                        {row.answer_sw && <span>🇹🇿 Kiswahili</span>}
                                        {row.cta_url && (
                                            <span className="text-primary">
                                                → {row.cta_label} ({row.cta_url})
                                            </span>
                                        )}
                                    </div>
                                </div>

                                {canManage && (
                                    <div className="flex shrink-0 gap-1">
                                        <button
                                            type="button"
                                            onClick={() => onEdit(row)}
                                            aria-label="Edit"
                                            className="flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        >
                                            <Pencil className="size-4" />
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (confirm(`Remove "${row.question}"?`)) {
                                                    router.delete(
                                                        route('admin.assistant.knowledge.destroy', row.id),
                                                        { preserveScroll: true },
                                                    );
                                                }
                                            }}
                                            aria-label="Delete"
                                            className="flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ))}
        </div>
    );
}

function Editor({
    row,
    question,
    topics,
    onClose,
}: {
    row: KnowledgeRow | null;
    question: string;
    topics: Record<string, string>;
    onClose: () => void;
}) {
    const { data, setData, post, patch, processing, errors } = useForm({
        ...EMPTY,
        ...(row
            ? {
                  topic: row.topic,
                  question: row.question,
                  question_sw: row.question_sw ?? '',
                  answer: row.answer,
                  answer_sw: row.answer_sw ?? '',
                  cta_label: row.cta_label ?? '',
                  cta_url: row.cta_url ?? '',
                  keywords: row.keywords ?? '',
                  status: row.status,
                  sort_order: row.sort_order,
              }
            : { question }),
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (row) {
            patch(route('admin.assistant.knowledge.update', row.id), options);
        } else {
            post(route('admin.assistant.knowledge.store'), options);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-background/70 p-0 backdrop-blur-sm sm:items-center sm:p-6">
            <form
                onSubmit={submit}
                className="flex max-h-[92dvh] w-full max-w-2xl flex-col overflow-hidden rounded-t-2xl border border-border bg-card shadow-2xl sm:rounded-2xl"
            >
                <header className="flex items-center justify-between border-b border-border px-5 py-3.5">
                    <h2 className="flex items-center gap-2 font-heading font-bold">
                        <Sparkles className="size-4 text-primary" />
                        {row ? 'Edit answer' : 'New answer'}
                    </h2>

                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted"
                    >
                        <X className="size-4" />
                    </button>
                </header>

                <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Topic" error={errors.topic}>
                            <select
                                value={data.topic}
                                onChange={(event) => setData('topic', event.target.value)}
                                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                {Object.entries(topics).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Shown" error={errors.status}>
                            <select
                                value={data.status}
                                onChange={(event) => setData('status', event.target.value)}
                                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                <option value="active">Active</option>
                                <option value="hidden">Hidden</option>
                            </select>
                        </Field>
                    </div>

                    <Field label="Question (English)" error={errors.question}>
                        <Input value={data.question} onChange={(v) => setData('question', v)} />
                    </Field>

                    <Field label="Question (Kiswahili)" error={errors.question_sw} optional>
                        <Input value={data.question_sw} onChange={(v) => setData('question_sw', v)} />
                    </Field>

                    <Field
                        label="Answer (English)"
                        error={errors.answer}
                        hint="Under 70 words. Say what it is, not what it costs — the price comes from the plan list."
                    >
                        <Textarea value={data.answer} onChange={(v) => setData('answer', v)} />
                    </Field>

                    <Field label="Answer (Kiswahili)" error={errors.answer_sw} optional>
                        <Textarea value={data.answer_sw} onChange={(v) => setData('answer_sw', v)} />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Button label" error={errors.cta_label} optional>
                            <Input
                                value={data.cta_label}
                                onChange={(v) => setData('cta_label', v)}
                                placeholder="See pricing"
                            />
                        </Field>

                        <Field label="Button link" error={errors.cta_url} optional>
                            <Input
                                value={data.cta_url}
                                onChange={(v) => setData('cta_url', v)}
                                placeholder="/pricing"
                            />
                        </Field>
                    </div>

                    <Field
                        label="Other ways people ask this"
                        error={errors.keywords}
                        optional
                        hint="Comma separated, two words or more each. A single word matches too loosely to be trusted."
                    >
                        <Textarea
                            value={data.keywords}
                            onChange={(v) => setData('keywords', v)}
                            rows={2}
                        />
                    </Field>
                </div>

                <footer className="flex justify-end gap-2 border-t border-border px-5 py-3.5">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-border px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {row ? 'Save' : 'Add answer'}
                    </button>
                </footer>
            </form>
        </div>
    );
}

function Stat({
    label,
    value,
    note,
    alarming,
}: {
    label: string;
    value: number;
    note: string;
    alarming?: boolean;
}) {
    return (
        <div className="rounded-xl border border-border bg-card px-4 py-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={[
                    'mt-1 font-heading text-2xl font-extrabold',
                    alarming ? 'text-destructive' : '',
                ].join(' ')}
            >
                {value}
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">{note}</p>
        </div>
    );
}

function Outcome({ value }: { value: string | null }) {
    const style =
        value === 'knowledge'
            ? 'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]'
            : value === 'ai'
              ? 'bg-primary/10 text-primary'
              : 'bg-destructive/10 text-destructive';

    const label = value === 'knowledge' ? 'written' : value === 'ai' ? 'AI' : 'missed';

    return (
        <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${style}`}>
            {label}
        </span>
    );
}

function Panel({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-xl border border-border bg-card px-4 py-3.5">
            <h2 className="font-heading text-sm font-bold">{title}</h2>
            {subtitle && <p className="mt-0.5 text-xs text-muted-foreground">{subtitle}</p>}
            <div className="mt-2">{children}</div>
        </section>
    );
}

function Field({
    label,
    error,
    hint,
    optional,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    optional?: boolean;
    children: React.ReactNode;
}) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-xs font-medium text-muted-foreground">
                {label}
                {optional && <span className="ml-1 font-normal">(optional)</span>}
            </span>

            {children}

            {hint && !error && <span className="mt-1 block text-xs text-muted-foreground">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-destructive">{error}</span>}
        </label>
    );
}

function Input({
    value,
    onChange,
    placeholder,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
}) {
    return (
        <input
            type="text"
            value={value}
            placeholder={placeholder}
            onChange={(event) => onChange(event.target.value)}
            className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
    );
}

function Textarea({
    value,
    onChange,
    rows = 3,
}: {
    value: string;
    onChange: (value: string) => void;
    rows?: number;
}) {
    return (
        <textarea
            value={value}
            rows={rows}
            onChange={(event) => onChange(event.target.value)}
            className="w-full resize-none rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
    );
}
