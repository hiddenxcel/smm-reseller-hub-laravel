import { Button } from '@/components/ui/button';
import { Check, Copy } from 'lucide-react';
import { ReactNode, useState } from 'react';

/**
 * The small pieces the API tabs share.
 */

export function Card({
    title,
    description,
    children,
    footer,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <section className="rounded-xl border border-border bg-card">
            <div className="border-b border-border px-5 py-4">
                <h2 className="font-heading text-base font-bold">{title}</h2>
                {description && (
                    <p className="mt-1 text-sm text-muted-foreground">{description}</p>
                )}
            </div>

            <div className="px-5 py-5">{children}</div>

            {footer && (
                <div className="flex justify-end border-t border-border px-5 py-3">{footer}</div>
            )}
        </section>
    );
}

export function EmptyState({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}

/**
 * A value with a copy button.
 *
 * Everything on these screens is meant to be pasted into someone else's code,
 * so copying is the primary action rather than a convenience — the value is
 * shown in a monospace face and never truncated in a way that would copy
 * short.
 */
export function CopyBox({
    value,
    label,
    className = '',
}: {
    value: string;
    label: string;
    className?: string;
}) {
    const [copied, setCopied] = useState(false);

    return (
        <div className={`flex items-stretch gap-2 ${className}`}>
            <code className="scroll-slim min-w-0 flex-1 overflow-x-auto rounded-lg border border-border bg-muted/40 px-3 py-2 font-mono text-sm whitespace-nowrap">
                {value}
            </code>

            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-center"
                onClick={() => {
                    navigator.clipboard?.writeText(value);
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 2000);
                }}
                aria-label={`Copy ${label}`}
            >
                {copied ? (
                    <>
                        <Check className="size-3.5" /> Copied
                    </>
                ) : (
                    <Copy className="size-3.5" />
                )}
            </Button>
        </div>
    );
}

export function StatusChip({ status }: { status: 'active' | 'revoked' }) {
    const isActive = status === 'active';

    return (
        <span
            className={[
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium',
                isActive
                    ? 'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]'
                    : 'bg-muted text-muted-foreground',
            ].join(' ')}
        >
            <span
                className={[
                    'size-1.5 rounded-full',
                    isActive ? 'bg-[#006300] dark:bg-[#0ca30c]' : 'bg-muted-foreground',
                ].join(' ')}
                aria-hidden
            />
            {isActive ? 'Active' : 'Revoked'}
        </span>
    );
}

export function dateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** A customer's display name, falling back to the number the bot knows them by. */
export function customerName(customer: { name: string | null; phone: string } | null): string {
    if (customer === null) {
        return 'Unknown';
    }

    return customer.name?.trim() || customer.phone;
}
