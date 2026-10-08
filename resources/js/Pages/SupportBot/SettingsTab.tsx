import { router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Card, Field, SaveBar, Toggle, inputClass } from '../OrderBot/bits';
import { PhoneList } from '../OrderBot/PhoneList';
import { Language, SupportSettings, Verification } from './types';

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
        <div className="space-y-4 sm:space-y-6">
            <VerificationForm verification={settings.verification} />
            <MainForm settings={settings} languages={languages} />
            <TestNumbersForm testNumbers={settings.testNumbers} />
        </div>
    );
}

/**
 * Lets a customer prove an account on the panel is theirs.
 *
 * The panel's Admin API is what can find a customer, put a code in their
 * account and read their orders; the reseller key cannot. Its key is a staff
 * credential, so it is typed here, kept encrypted, and never shown again.
 */
function VerificationForm({ verification }: { verification: Verification }) {
    const form = useForm({
        adminApiUrl: verification.adminApiUrl ?? '',
        adminApiKey: '',
        required: verification.required,
        clear: false,
    });

    const connected = verification.adminApiUrl !== null && verification.hasKey;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('support-bot.admin-api'), {
            preserveScroll: true,
            onSuccess: () => form.setData('adminApiKey', ''),
        });
    };

    const disconnect = () => {
        if (!window.confirm('Disconnect the Admin API? Customers will no longer be asked to verify.')) {
            return;
        }

        router.post(
            route('support-bot.admin-api'),
            { required: form.data.required, clear: true },
            { preserveScroll: true },
        );
    };

    return (
        <form onSubmit={submit}>
            <Card
                title="Account verification"
                description={
                    connected
                        ? `Connected to ${verification.panelName ?? 'your panel'}. ${verification.linkedCount} customer${verification.linkedCount === 1 ? '' : 's'} verified.`
                        : 'Connect your panel\u2019s Admin API so customers can prove an account is theirs, and the bot can act on their orders.'
                }
            >
                <div className="space-y-4">
                    <Field
                        label="Panel address"
                        hint="For example https://yourpanel.com"
                        error={form.errors.adminApiUrl}
                    >
                        <input
                            className={inputClass}
                            value={form.data.adminApiUrl}
                            onChange={(event) => form.setData('adminApiUrl', event.target.value)}
                            placeholder="https://yourpanel.com"
                            inputMode="url"
                        />
                    </Field>

                    <Field
                        label="Admin API key"
                        hint={
                            verification.hasKey
                                ? 'A key is saved. Leave blank to keep it.'
                                : 'From a staff account that has Admin API access.'
                        }
                        error={form.errors.adminApiKey}
                    >
                        <input
                            type="password"
                            autoComplete="off"
                            className={inputClass}
                            value={form.data.adminApiKey}
                            onChange={(event) => form.setData('adminApiKey', event.target.value)}
                        />
                    </Field>

                    <Toggle
                        label="Ask customers to verify first"
                        description="Before refill, status or cancel, the customer proves the account is theirs with a code sent to their tickets."
                        checked={form.data.required}
                        onChange={(value) => form.setData('required', value)}
                    />

                    <div className="flex flex-col gap-2 sm:flex-row-reverse">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-60"
                        >
                            {form.processing ? 'Saving\u2026' : connected ? 'Save and test' : 'Connect'}
                        </button>

                        {connected && (
                            <button
                                type="button"
                                onClick={disconnect}
                                className="rounded-xl px-5 py-2.5 text-sm text-muted-foreground hover:text-destructive"
                            >
                                Disconnect
                            </button>
                        )}
                    </div>
                </div>
            </Card>
        </form>
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
        <form onSubmit={submit} className="space-y-4 sm:space-y-6">
            <Card
                title="Menu options"
                description="Switch off what you cannot service. The others stay on the menu."
            >
                <div className="-my-3 divide-y divide-border">
                    <Toggle
                        label="Order status"
                        description="Looks the order up on your panel."
                        checked={form.data.commands.status}
                        onChange={(value) => setCommand('status', value)}
                    />
                    <Toggle
                        label="Refill"
                        description="Only when a guarantee rule allows it."
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
                </div>

                <p className="mt-4 border-t border-border pt-3 text-xs text-muted-foreground">
                    “Talk to a human”, top-up help and FAQ cannot be switched off — a customer
                    must always be able to reach a person.
                </p>
            </Card>

            <Card title="Anti-spam" description="Staff numbers are never blocked.">
                <div className="-mt-3">
                    <Toggle
                        label="Block repeated messages"
                        checked={form.data.spam.enabled}
                        onChange={(value) => setSpam('enabled', value)}
                    />
                </div>

                {form.data.spam.enabled && (
                    <div className="mt-2 grid grid-cols-3 gap-3">
                        <Field
                            label="After"
                            hint="messages"
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
                            label="Within"
                            hint="minutes"
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
                            label="Block for"
                            hint="minutes"
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
                )}
            </Card>

            <Card
                title="Language"
                description="What the bot speaks. Your wording overrides are kept per language."
            >
                <select
                    className={inputClass}
                    value={form.data.lang}
                    onChange={(event) => form.setData('lang', event.target.value)}
                    aria-label="Bot language"
                >
                    {languages.map((language) => (
                        <option key={language.code} value={language.code}>
                            {language.name}
                        </option>
                    ))}
                </select>
            </Card>

            <Card
                title="Staff numbers"
                description="They are told when a customer asks for a person, and skip anti-spam."
            >
                <PhoneList
                    numbers={form.data.staff}
                    onChange={(numbers) => form.setData('staff', numbers)}
                    empty="No staff numbers yet — nobody is told when a customer asks for help."
                />
            </Card>

            <SaveBar processing={form.processing} dirty={form.isDirty} />
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
                description="In test mode, these are the only numbers the bot answers."
                footer={
                    <button
                        type="submit"
                        disabled={form.processing || !form.isDirty}
                        className="rounded-xl border border-border px-4 py-2.5 text-sm font-medium disabled:opacity-50"
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
