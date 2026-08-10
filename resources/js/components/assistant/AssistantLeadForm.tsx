import { Check, Loader2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { t } from './strings';
import type { Locale } from './useAssistant';

/**
 * "Have someone get back to me."
 *
 * Rendered in place of the message list rather than in a modal. A dialog
 * stacked on top of a panel that is itself stacked on the page is one layer
 * too many, and it hides the conversation the visitor is asking about at the
 * moment they are describing it.
 *
 * Three fields, one of them optional. Every extra field here is a person who
 * decides it is not worth it.
 */
export default function AssistantLeadForm({
    token,
    locale,
    onDone,
    onCancel,
}: {
    token: string | null;
    locale: Locale;
    onDone: (whatsapp: string | null) => void;
    onCancel: () => void;
}) {
    const copy = t(locale);

    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [message, setMessage] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // No token means no conversation was ever opened — the first question can
    // fail before one exists, and this form is offered from that same failure.
    // Without this the button would be enabled and do nothing at all.
    const ready = name.trim() !== '' && phone.trim() !== '' && token !== null && !sending;

    async function submit(event: FormEvent) {
        event.preventDefault();

        if (!ready || token === null) {
            return;
        }

        setSending(true);
        setError(null);

        try {
            const cookie = document.cookie
                .split('; ')
                .find((entry) => entry.startsWith('XSRF-TOKEN='));

            const response = await fetch('/assistant/lead', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': cookie
                        ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
                        : '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ token, name, phone, message }),
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const data = (await response.json()) as { whatsapp: string | null };

            onDone(data.whatsapp);
        } catch {
            setError(copy.leadFailed);
        } finally {
            setSending(false);
        }
    }

    return (
        <form
            onSubmit={submit}
            className="flex animate-in flex-col gap-3 px-4 py-5 fade-in slide-in-from-bottom-2 duration-300"
        >
            <div>
                <p className="text-sm font-bold">{copy.leadTitle}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {copy.leadBody}
                </p>
            </div>

            {token === null && (
                <p className="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                    {copy.leadNoChat}
                </p>
            )}

            <Field label={copy.leadName} value={name} onChange={setName} autoFocus />
            <Field
                label={copy.leadPhone}
                value={phone}
                onChange={setPhone}
                type="tel"
                placeholder="+255 700 000 000"
            />

            <label className="flex flex-col gap-1.5">
                <span className="text-xs font-medium text-muted-foreground">
                    {copy.leadExtra}{' '}
                    <span className="font-normal">{copy.optional}</span>
                </span>
                <textarea
                    value={message}
                    onChange={(event) => setMessage(event.target.value)}
                    rows={2}
                    maxLength={1000}
                    className="resize-none rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                />
            </label>

            {error && <p className="text-xs text-destructive">{error}</p>}

            <div className="flex gap-2">
                <button
                    type="button"
                    onClick={onCancel}
                    className="rounded-lg border border-border px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                >
                    {copy.back}
                </button>

                <button
                    type="submit"
                    disabled={!ready}
                    className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-40"
                >
                    {sending ? (
                        <Loader2 className="size-4 animate-spin" />
                    ) : (
                        <Check className="size-4" />
                    )}
                    {copy.sendToSupport}
                </button>
            </div>
        </form>
    );
}

function Field({
    label,
    value,
    onChange,
    type = 'text',
    placeholder,
    autoFocus,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    type?: string;
    placeholder?: string;
    autoFocus?: boolean;
}) {
    return (
        <label className="flex flex-col gap-1.5">
            <span className="text-xs font-medium text-muted-foreground">{label}</span>
            <input
                type={type}
                value={value}
                placeholder={placeholder}
                autoFocus={autoFocus}
                onChange={(event) => onChange(event.target.value)}
                className="rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            />
        </label>
    );
}
