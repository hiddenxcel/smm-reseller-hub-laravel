import { useForm } from '@inertiajs/react';
import { Card, Field, SaveBar, Toggle, inputClass } from './bits';
import { Commands, Spam } from './types';

const COMMANDS: { key: keyof Commands; label: string; description: string }[] = [
    {
        key: 'refill',
        label: 'Refill',
        description: 'Ask for a refill on an order that dropped.',
    },
    {
        key: 'status',
        label: 'Status',
        description: 'Check how far along an order is.',
    },
    {
        key: 'cancel',
        label: 'Cancel',
        description: 'Cancel an order the panel has not started.',
    },
    {
        key: 'speedup',
        label: 'Speed up',
        description: 'Ask for faster delivery — only if your panel supports it.',
    },
];

/**
 * What the bot will do when asked, and how much repetition it tolerates.
 *
 * Anti-spam sits here rather than under Settings because it is the same
 * decision: both are limits on what a customer can make the bot do.
 */
export function CommandsTab({ commands, spam }: { commands: Commands; spam: Spam }) {
    const form = useForm({ commands, spam });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('order-bot.commands'), { preserveScroll: true });
            }}
            className="space-y-4 sm:space-y-6"
        >
            <Card
                title="What customers can do"
                description="Turn off anything your panel cannot do — a command that always fails is worse than none."
            >
                <div className="divide-y divide-border">
                    {COMMANDS.map((command) => (
                        <Toggle
                            key={command.key}
                            label={command.label}
                            description={command.description}
                            checked={form.data.commands[command.key]}
                            onChange={(value) =>
                                form.setData('commands', {
                                    ...form.data.commands,
                                    [command.key]: value,
                                })
                            }
                        />
                    ))}
                </div>
            </Card>

            <Card title="Anti-spam" description="Staff numbers are never blocked.">
                <Toggle
                    label="Block repeated messages"
                    checked={form.data.spam.enabled}
                    onChange={(value) =>
                        form.setData('spam', { ...form.data.spam, enabled: value })
                    }
                />

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
                                value={form.data.spam.repeat_threshold}
                                onChange={(event) =>
                                    form.setData('spam', {
                                        ...form.data.spam,
                                        repeat_threshold: Number(event.target.value),
                                    })
                                }
                                className={inputClass}
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
                                value={form.data.spam.window_minutes}
                                onChange={(event) =>
                                    form.setData('spam', {
                                        ...form.data.spam,
                                        window_minutes: Number(event.target.value),
                                    })
                                }
                                className={inputClass}
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
                                value={form.data.spam.disable_minutes}
                                onChange={(event) =>
                                    form.setData('spam', {
                                        ...form.data.spam,
                                        disable_minutes: Number(event.target.value),
                                    })
                                }
                                className={inputClass}
                            />
                        </Field>
                    </div>
                )}
            </Card>

            <SaveBar processing={form.processing} dirty={form.isDirty} />
        </form>
    );
}
