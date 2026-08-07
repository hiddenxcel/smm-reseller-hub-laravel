import { Button } from '@/components/ui/button';
import { Ban, Loader2, Megaphone, ShieldCheck, Tag, X } from 'lucide-react';
import { useState } from 'react';

type BulkAction = 'block' | 'unblock' | 'tag' | 'untag' | 'broadcast';

/**
 * The bulk bar, floating over the table once anything is ticked.
 *
 * Broadcast opens a composer rather than firing straight away — it is the one
 * action here that reaches the customer's phone, and it should take a
 * deliberate second step. Blocking and tagging are reversible; a message sent
 * is not.
 */
export default function BulkBar({
    count,
    totalMatching,
    allSelected,
    limits,
    hasWhatsApp,
    pending,
    onAction,
    onSelectAllMatching,
    onClear,
}: {
    count: number;
    totalMatching: number;
    allSelected: boolean;
    limits: { default: number; broadcast: number };
    hasWhatsApp: boolean;
    pending: boolean;
    onAction: (action: BulkAction, payload?: { tag?: string; text?: string }) => void;
    onSelectAllMatching: () => void;
    onClear: () => void;
}) {
    const [composing, setComposing] = useState<'tag' | 'untag' | 'broadcast' | null>(null);
    const [draft, setDraft] = useState('');

    if (count === 0) {
        return null;
    }

    const overBroadcastLimit = count > limits.broadcast;

    const send = () => {
        const value = draft.trim();

        if (value === '' || composing === null) {
            return;
        }

        onAction(composing, composing === 'broadcast' ? { text: value } : { tag: value });
        setDraft('');
        setComposing(null);
    };

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex justify-center px-4 pb-5">
            <div className="pointer-events-auto w-full max-w-2xl rounded-xl border border-border bg-popover shadow-2xl">
                <div className="flex flex-wrap items-center gap-3 px-4 py-3">
                    <span className="font-data text-sm font-semibold tabular-nums">
                        {count.toLocaleString('en-US')} selected
                    </span>

                    {!allSelected && totalMatching > count && (
                        <button
                            type="button"
                            onClick={onSelectAllMatching}
                            className="text-sm text-primary underline-offset-4 hover:underline"
                        >
                            Select all{' '}
                            {Math.min(totalMatching, limits.default).toLocaleString('en-US')}{' '}
                            matching
                        </button>
                    )}

                    <div className="h-5 w-px bg-border" aria-hidden />

                    <div className="flex flex-wrap items-center gap-1.5">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={pending}
                            onClick={() => onAction('block')}
                        >
                            <Ban className="size-3.5" />
                            Block
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={pending}
                            onClick={() => onAction('unblock')}
                        >
                            <ShieldCheck className="size-3.5" />
                            Unblock
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={pending}
                            onClick={() =>
                                setComposing((current) => (current === 'tag' ? null : 'tag'))
                            }
                        >
                            <Tag className="size-3.5" />
                            Tag
                        </Button>
                        <Button
                            size="sm"
                            variant={composing === 'broadcast' ? 'default' : 'outline'}
                            disabled={pending || overBroadcastLimit || !hasWhatsApp}
                            title={
                                !hasWhatsApp
                                    ? 'Connect a WhatsApp number first.'
                                    : overBroadcastLimit
                                      ? `A broadcast is one message per customer, so it is capped at ${limits.broadcast}.`
                                      : undefined
                            }
                            onClick={() =>
                                setComposing((current) =>
                                    current === 'broadcast' ? null : 'broadcast',
                                )
                            }
                        >
                            <Megaphone className="size-3.5" />
                            Message
                        </Button>
                    </div>

                    {pending && (
                        <Loader2
                            className="size-4 animate-spin text-muted-foreground"
                            aria-hidden
                        />
                    )}

                    <button
                        type="button"
                        onClick={onClear}
                        className="ms-auto rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-accent"
                        aria-label="Clear selection"
                    >
                        <X className="size-4" />
                    </button>
                </div>

                {composing !== null && (
                    <div className="border-t border-border px-4 py-3">
                        {composing === 'broadcast' ? (
                            <>
                                <textarea
                                    value={draft}
                                    onChange={(event) => setDraft(event.target.value)}
                                    rows={2}
                                    autoFocus
                                    placeholder="Write the message everyone selected will receive…"
                                    aria-label="Broadcast message"
                                    className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                                />
                                {/* Set expectations before they press send: some
                                    recipients will be skipped, and that is normal
                                    rather than a failure. */}
                                <p className="mt-1.5 text-xs text-muted-foreground">
                                    WhatsApp only delivers to customers who messaged you in the
                                    last 24 hours. The rest are skipped automatically.
                                </p>
                            </>
                        ) : (
                            <input
                                value={draft}
                                onChange={(event) => setDraft(event.target.value)}
                                autoFocus
                                placeholder="Tag name"
                                aria-label="Tag name"
                                onKeyDown={(event) => event.key === 'Enter' && send()}
                                className="h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                            />
                        )}

                        <div className="mt-2 flex justify-end gap-2">
                            {composing === 'tag' && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={pending || draft.trim() === ''}
                                    onClick={() => {
                                        onAction('untag', { tag: draft.trim() });
                                        setDraft('');
                                        setComposing(null);
                                    }}
                                >
                                    Remove tag
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    setComposing(null);
                                    setDraft('');
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                size="sm"
                                disabled={pending || draft.trim() === ''}
                                onClick={send}
                            >
                                {composing === 'broadcast'
                                    ? `Send to ${count.toLocaleString('en-US')}`
                                    : 'Add tag'}
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
