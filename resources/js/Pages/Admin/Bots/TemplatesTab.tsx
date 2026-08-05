import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { Empty } from '../bits';
import { TemplateRow } from '../types';

/**
 * The wording every reseller starts with.
 *
 * The number that matters on each row is `overriddenBy`: resellers who wrote
 * their own version keep it, and everyone else changes the moment this is
 * saved. Editing platform copy without knowing how many people it reaches is
 * how a typo ends up in forty thousand customer conversations.
 */
export default function TemplatesTab({
    bot,
    rows,
    languages,
    lang,
    canManage,
}: {
    bot: string;
    rows: TemplateRow[];
    languages: Array<{ code: string; name: string }>;
    lang: string;
    canManage: boolean;
}) {
    const [editing, setEditing] = useState<TemplateRow | null>(null);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    What the bot says when a reseller has not written their own
                    wording.
                </p>

                <select
                    value={lang}
                    onChange={(e) =>
                        router.get(route('admin.bots', [bot, 'templates']), {
                            lang: e.target.value,
                        })
                    }
                    className="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                >
                    {languages.map((language) => (
                        <option key={language.code} value={language.code}>
                            {language.name}
                        </option>
                    ))}
                </select>
            </div>

            {rows.length === 0 ? (
                <Empty>No templates for this bot.</Empty>
            ) : (
                <div className="space-y-2">
                    {rows.map((row) => (
                        <div
                            key={row.key}
                            className="rounded-xl border border-border bg-card p-4"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-mono text-sm font-semibold">
                                        {row.key}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {row.about}
                                    </p>
                                </div>

                                <div className="flex shrink-0 items-center gap-2">
                                    {row.isSet ? (
                                        <span className="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-primary">
                                            Customised
                                        </span>
                                    ) : (
                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground">
                                            Built-in
                                        </span>
                                    )}

                                    {canManage && (
                                        <button
                                            type="button"
                                            onClick={() => setEditing(row)}
                                            className="rounded-lg border border-border px-2 py-1 text-xs font-medium transition-colors hover:bg-accent"
                                        >
                                            Edit
                                        </button>
                                    )}
                                </div>
                            </div>

                            <p className="mt-2 whitespace-pre-line rounded-lg bg-muted/40 px-3 py-2 text-sm">
                                {row.content ?? row.builtIn}
                            </p>

                            {row.overriddenBy > 0 && (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {row.overriddenBy} reseller
                                    {row.overriddenBy === 1 ? ' has' : 's have'} written
                                    their own version — they will not see this.
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {editing && (
                <EditDialog
                    row={editing}
                    bot={bot}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

function EditDialog({
    row,
    bot,
    onClose,
}: {
    row: TemplateRow;
    bot: string;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        key: row.key,
        lang: row.lang,
        bot,
        content: row.content ?? row.builtIn,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        post(route('admin.bots.templates'), { onSuccess: onClose });
    };

    const reset = () => {
        router.post(
            route('admin.bots.templates'),
            { key: row.key, lang: row.lang, bot, content: '' },
            { onSuccess: onClose },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-foreground/40 p-4">
            <form
                onSubmit={submit}
                className="my-8 w-full max-w-2xl rounded-xl border border-border bg-card p-6"
            >
                <h2 className="font-heading text-lg font-extrabold">{row.key}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{row.about}</p>

                {/* The consequence, stated where the decision is made. */}
                <p className="mt-3 flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        Saving changes what every reseller who has not written their
                        own version sends, immediately.
                        {row.overriddenBy > 0 && (
                            <> {row.overriddenBy} have their own and will not change.</>
                        )}
                    </span>
                </p>

                <label className="mt-4 block text-xs font-medium text-muted-foreground">
                    Message
                </label>
                <textarea
                    value={data.content}
                    onChange={(e) => setData('content', e.target.value)}
                    rows={6}
                    maxLength={4000}
                    autoFocus
                    className="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
                {errors.content && (
                    <p className="mt-1 text-xs text-destructive">{errors.content}</p>
                )}

                <details className="mt-3">
                    <summary className="cursor-pointer text-xs text-muted-foreground">
                        Show the built-in wording
                    </summary>
                    <p className="mt-2 whitespace-pre-line rounded-lg bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                        {row.builtIn}
                    </p>
                </details>

                <div className="mt-6 flex flex-wrap justify-end gap-2">
                    {row.isSet && (
                        <button
                            type="button"
                            onClick={reset}
                            className="mr-auto inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent"
                        >
                            <RotateCcw className="size-3.5" />
                            Reset to built-in
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-border px-3 py-2 text-sm transition-colors hover:bg-accent"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Save for everyone'}
                    </button>
                </div>
            </form>
        </div>
    );
}
