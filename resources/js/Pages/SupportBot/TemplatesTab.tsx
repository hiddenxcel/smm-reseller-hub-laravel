import { router, useForm } from '@inertiajs/react';
import { Card, inputClass } from '../OrderBot/bits';
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

    return (
        <div className="space-y-6">
            <Card title="How this works">
                <p className="text-sm text-muted-foreground">
                    Leave a box empty and the bot uses its built-in wording. Anything you
                    write replaces it, in{' '}
                    <span className="font-medium text-foreground">
                        {language?.name ?? data.lang}
                    </span>{' '}
                    — the language this bot is set to. Change that under Settings.
                </p>
            </Card>

            {data.rows.map((row) => (
                <TemplateEditor key={row.key} row={row} lang={data.lang} />
            ))}
        </div>
    );
}

function TemplateEditor({ row, lang }: { row: TemplateRow; lang: string }) {
    const form = useForm({
        key: row.key,
        lang,
        content: row.content,
    });

    const dirty = form.data.content !== row.content;

    return (
        <Card title={row.key} description={row.description}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('support-bot.templates.update'), { preserveScroll: true });
                }}
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
                        className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
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

                    <span className="ml-auto text-xs text-muted-foreground">
                        {row.custom ? 'Your wording' : 'Built-in wording'}
                    </span>
                </div>
            </form>
        </Card>
    );
}
