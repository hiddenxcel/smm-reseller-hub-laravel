import { router, useForm } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { inputClass } from '../OrderBot/bits';
import { Language, TemplateRow, Templates } from './types';

/**
 * The bot's own wording, in the reseller's voice.
 *
 * Every key here is one the handler actually sends — the messenger looks for
 * an override just before it puts a message on the wire, and falls back to the
 * built-in text when there is none. So an empty box is not an empty message:
 * it means "use the default", which is why clearing one deletes the override
 * rather than storing a blank.
 *
 * A dozen open text boxes is a wall, so each message is a closed row — name,
 * what it is for, and whether it has been changed — that opens to edit.
 *
 * The language comes from the bot's own setting rather than a picker here.
 * Editing Turkish text while the bot speaks English would produce wording that
 * silently never appears.
 */
export function TemplatesTab({
    data,
    languages,
}: {
    data: Templates;
    languages: Language[];
}) {
    const language = languages.find((entry) => entry.code === data.lang);
    const [open, setOpen] = useState<string | null>(null);

    return (
        <div className="space-y-4 sm:space-y-6">
            <p className="text-sm text-muted-foreground">
                Open a message to replace the built-in wording, in{' '}
                <span className="font-medium text-foreground">{language?.name ?? data.lang}</span>.
                Leave it empty and the bot keeps using its own. The language is set under Settings.
            </p>

            <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                {data.rows.map((row) => (
                    <TemplateItem
                        key={row.key}
                        row={row}
                        lang={data.lang}
                        open={open === row.key}
                        onToggle={() => setOpen(open === row.key ? null : row.key)}
                    />
                ))}
            </ul>
        </div>
    );
}

/** SUPPORT_MENU → "Support menu": the key is for the code, not for people. */
function humanise(key: string): string {
    const words = key.toLowerCase().split('_').join(' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

function TemplateItem({
    row,
    lang,
    open,
    onToggle,
}: {
    row: TemplateRow;
    lang: string;
    open: boolean;
    onToggle: () => void;
}) {
    const form = useForm({
        key: row.key,
        lang,
        content: row.content,
    });

    const dirty = form.data.content !== row.content;

    return (
        <li>
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="flex w-full items-center gap-3 p-3.5 text-left transition-colors hover:bg-accent/40"
            >
                <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium">{humanise(row.key)}</span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {row.description}
                    </span>
                </span>

                {row.custom && (
                    <span className="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary">
                        Yours
                    </span>
                )}

                <ChevronDown
                    className={`size-4 shrink-0 text-muted-foreground transition-transform ${
                        open ? 'rotate-180' : ''
                    }`}
                    aria-hidden
                />
            </button>

            {open && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(route('support-bot.templates.update'), {
                            preserveScroll: true,
                        });
                    }}
                    className="border-t border-border bg-muted/30 p-3.5"
                >
                    <textarea
                        className={`${inputClass} min-h-28 font-data text-sm`}
                        value={form.data.content}
                        onChange={(event) => form.setData('content', event.target.value)}
                        placeholder="Using the built-in wording. Type here to replace it."
                        maxLength={4000}
                    />

                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            disabled={form.processing || !dirty}
                            className="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60"
                        >
                            {form.processing ? 'Saving…' : 'Save'}
                        </button>

                        {row.custom && (
                            <button
                                type="button"
                                disabled={form.processing}
                                // Posts an empty content, which the server reads as
                                // "use the default" and deletes the override. The
                                // reload afterwards is what refreshes `custom`.
                                onClick={() =>
                                    router.post(
                                        route('support-bot.templates.update'),
                                        { key: row.key, lang, content: '' },
                                        { preserveScroll: true },
                                    )
                                }
                                className="text-sm text-muted-foreground underline disabled:opacity-60"
                            >
                                Reset to default
                            </button>
                        )}
                    </div>
                </form>
            )}
        </li>
    );
}
