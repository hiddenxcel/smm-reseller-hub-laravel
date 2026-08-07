import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { Card, Field, Toggle, inputClass } from './bits';
import { Commands, Spam } from './types';

const COMMANDS: { key: keyof Commands; label: string; description: string }[] = [
    {
        key: 'refill',
        label: 'Refill',
        description: 'Customers can ask for a refill on an order that dropped.',
    },
    {
        key: 'status',
        label: 'Status',
        description: 'Customers can check how far along an order is.',
    },
    {
        key: 'cancel',
        label: 'Cancel',
        description: 'Customers can cancel an order the panel has not started.',
    },
    {
        key: 'speedup',
        label: 'Speed up',
        description: 'Customers can request faster delivery. Only enable it if your panel supports it.',
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
            className="space-y-6"
        >
            <Card
                title="Commands"
                description="Turn off anything your panel cannot actually do — a command that always fails is worse than one that is missing."
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

            <Card
                title="Anti-spam"
                description="Staff numbers are never blocked by this."
            >
                <Toggle
                    label="Block repeated messages"
                    checked={form.data.spam.enabled}
                    onChange={(value) =>
                        form.setData('spam', { ...form.data.spam, enabled: value })
                    }
                />

                {form.data.spam.enabled && (
                    <div className="mt-4 grid gap-4 sm:grid-cols-3">
                        <Field
                            label="Messages"
                            hint="Before blocking"
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
                            hint="Minutes"
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
                            label="Blocked for"
                            hint="Minutes"
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

            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save changes'}
                </Button>
            </div>
        </form>
    );
}
