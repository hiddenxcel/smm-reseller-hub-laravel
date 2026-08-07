import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Card, Field, Toggle, inputClass } from '../OrderBot/bits';
import { PhoneList } from '../OrderBot/PhoneList';
import { Language, SupportSettings } from './types';

/**
 * Two forms, deliberately not one.
 *
 * Test numbers save on their own card because they belong to a different
 * moment — trying the bot before paying, rather than tuning it afterwards —
 * and because each form only writes the keys it owns, so saving one can never
 * wipe what the other holds.
 */
export function SettingsTab({
    settings,
    languages,
}: {
    settings: SupportSettings;
    languages: Language[];
}) {
    return (
        <div className="space-y-6">
            <MainForm settings={settings} languages={languages} />
            <TestNumbersForm testNumbers={settings.testNumbers} />
        </div>
    );
}

function MainForm({
    settings,
    languages,
}: {
    settings: SupportSettings;
    languages: Language[];
}) {
    const form = useForm({
        commands: settings.commands,
        spam: settings.spam,
        staff: settings.staff,
        lang: settings.lang,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('support-bot.settings'), { preserveScroll: true });
    };

    const setCommand = (key: keyof SupportSettings['commands'], value: boolean) =>
        form.setData('commands', { ...form.data.commands, [key]: value });

    const setSpam = (key: keyof SupportSettings['spam'], value: boolean | number) =>
        form.setData('spam', { ...form.data.spam, [key]: value });

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card
                title="Menu options"
                description="Switch off what you cannot service. The others stay on the menu."
            >
                <Toggle
                    label="Order status"
                    description="Looks the order up on your panel."
                    checked={form.data.commands.status}
                    onChange={(value) => setCommand('status', value)}
                />
                <Toggle
                    label="Refill"
                    description="Submitted only when a guarantee rule allows it."
                    checked={form.data.commands.refill}
                    onChange={(value) => setCommand('refill', value)}
                />
                <Toggle
                    label="Cancel"
                    description="Logs a cancellation request for your team."
                    checked={form.data.commands.cancel}
                    onChange={(value) => setCommand('cancel', value)}
                />
                <Toggle
                    label="Speed up"
                    description="Logs a request to prioritise the order."
                    checked={form.data.commands.speedup}
                    onChange={(value) => setCommand('speedup', value)}
                />

                <p className="mt-3 border-t border-border pt-3 text-xs text-muted-foreground">
                    “Talk to a human”, top-up help and FAQ cannot be switched off — a
                    customer must always be able to reach a person.
                </p>
            </Card>

            <Card
                title="Anti-spam"
                description="Stops one number flooding the bot. Your staff numbers are exempt."
            >
                <Toggle
                    label="Enabled"
                    checked={form.data.spam.enabled}
                    onChange={(value) => setSpam('enabled', value)}
                />

                <div className="mt-4 grid gap-4 sm:grid-cols-3">
                    <Field
                        label="Messages allowed"
                        hint="Before blocking."
                        error={form.errors['spam.repeat_threshold']}
                    >
                        <input
                            type="number"
                            min={2}
                            max={20}
                            className={inputClass}
                            value={form.data.spam.repeat_threshold}
                            onChange={(event) =>
                                setSpam('repeat_threshold', Number(event.target.value))
                            }
                        />
                    </Field>

                    <Field
                        label="Within (minutes)"
                        error={form.errors['spam.window_minutes']}
                    >
                        <input
                            type="number"
                            min={1}
                            max={120}
                            className={inputClass}
                            value={form.data.spam.window_minutes}
                            onChange={(event) =>
                                setSpam('window_minutes', Number(event.target.value))
                            }
                        />
                    </Field>

                    <Field
                        label="Blocked for (minutes)"
                        error={form.errors['spam.disable_minutes']}
                    >
                        <input
                            type="number"
                            min={1}
                            max={1440}
                            className={inputClass}
                            value={form.data.spam.disable_minutes}
                            onChange={(event) =>
                                setSpam('disable_minutes', Number(event.target.value))
                            }
                        />
                    </Field>
                </div>
            </Card>

            <Card
                title="Language"
                description="What the bot speaks, and which template overrides it looks for."
            >
                <Field label="Bot language">
                    <select
                        className={inputClass}
                        value={form.data.lang}
                        onChange={(event) => form.setData('lang', event.target.value)}
                    >
                        {languages.map((language) => (
                            <option key={language.code} value={language.code}>
                                {language.name}
                            </option>
                        ))}
                    </select>
                </Field>
            </Card>

            <Card
                title="Staff numbers"
                description="They get notified when a customer asks for a person, and skip anti-spam."
            >
                <PhoneList
                    numbers={form.data.staff}
                    onChange={(numbers) => form.setData('staff', numbers)}
                    empty="No staff numbers yet — nobody is notified when a customer asks for help."
                />
            </Card>

            <button
                type="submit"
                disabled={form.processing}
                className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
                {form.processing ? 'Saving…' : 'Save settings'}
            </button>
        </form>
    );
}

function TestNumbersForm({ testNumbers }: { testNumbers: string[] }) {
    const form = useForm({ testNumbers });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('support-bot.test-numbers'), { preserveScroll: true });
            }}
        >
            <Card
                title="Test numbers"
                description="While the subscription is in sandbox, these are the only numbers the bot answers."
                footer={
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg border border-input px-4 py-2 text-sm font-medium disabled:opacity-60"
                    >
                        {form.processing ? 'Saving…' : 'Save test numbers'}
                    </button>
                }
            >
                <PhoneList
                    numbers={form.data.testNumbers}
                    onChange={(numbers) => form.setData('testNumbers', numbers)}
                    empty="No test numbers yet."
                />
            </Card>
        </form>
    );
}
