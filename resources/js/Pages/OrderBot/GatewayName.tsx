import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { inputClass } from './bits';

/** WhatsApp shows no more than this of a list row's title. */
const MAX_NAME = 24;

/**
 * The name customers see for a gateway in the bot's payment menu.
 *
 * Blank puts the provider's own name back, which is why the default is shown as
 * the placeholder: the reseller can see what they would be replacing.
 */
export function GatewayName({
    code,
    current,
    fallback,
    label = 'Name customers see in the bot',
}: {
    code: string;
    current: string | null;
    fallback: string;
    label?: string;
}) {
    const [value, setValue] = useState(current ?? '');
    const [saving, setSaving] = useState(false);

    const changed = value.trim() !== (current ?? '');

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                setSaving(true);
                router.post(
                    route('order-bot.gateways.name', code),
                    { name: value.trim() },
                    { preserveScroll: true, onFinish: () => setSaving(false) },
                );
            }}
            className="flex flex-wrap items-end gap-2"
        >
            <label className="min-w-0 flex-1">
                <span className="mb-1 block text-xs font-medium text-muted-foreground">
                    {label}
                </span>
                <input
                    value={value}
                    maxLength={MAX_NAME}
                    onChange={(event) => setValue(event.target.value)}
                    placeholder={fallback}
                    className={inputClass}
                />
            </label>

            <span className="pb-2 text-xs tabular-nums text-muted-foreground">
                {value.length}/{MAX_NAME}
            </span>

            <Button type="submit" size="sm" variant="outline" disabled={saving || !changed}>
                {saving ? 'Saving…' : 'Save name'}
            </Button>
        </form>
    );
}
