import { AlertCircle } from 'lucide-react';
import { ReactNode } from 'react';

/**
 * Pieces shared across the settings tabs.
 */

/**
 * The one container every settings block uses. The title sits in the card
 * rather than over a rule, so a phone shows content sooner and the blocks read
 * as quiet surfaces, not forms in boxes.
 */
export function Card({
    title,
    description,
    children,
    footer,
    action,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <div className="mb-4 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="font-heading text-base font-bold">{title}</h2>
                    {description && (
                        <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
                    )}
                </div>
                {action}
            </div>

            {children}

            {footer && (
                <div className="mt-4 flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:justify-end *:w-full sm:*:w-auto">
                    {footer}
                </div>
            )}
        </section>
    );
}

export function FieldError({ message }: { message?: string }) {
    if (! message) {
        return null;
    }

    return (
        <p className="mt-2 flex items-start gap-1.5 text-sm text-destructive">
            <AlertCircle className="mt-0.5 size-4 shrink-0" />
            <span>{message}</span>
        </p>
    );
}

/**
 * Says what is missing without pretending it is broken.
 *
 * A reseller reaching a settings tab that is not set up yet has not done
 * anything wrong — they may never have needed it — so this is an amber note,
 * not a red error.
 */
export function NeedsAttention({
    children,
    tone = 'warning',
}: {
    children: ReactNode;
    tone?: 'warning' | 'info';
}) {
    const styles =
        tone === 'info'
            ? 'border-border bg-muted/40 text-muted-foreground'
            : 'border-[oklch(0.77_0.16_70/0.35)] bg-[oklch(0.77_0.16_70/0.1)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]';

    return (
        <div className={`flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ${styles}`}>
            <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden />
            <div>{children}</div>
        </div>
    );
}

export function EmptyState({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}

/** A small status label: icon-free, so it reads the same at any size. */
export function StatusBadge({
    tone,
    children,
}: {
    tone: 'good' | 'muted' | 'warn';
    children: ReactNode;
}) {
    const styles = {
        good: 'bg-primary/10 text-primary',
        muted: 'bg-muted text-muted-foreground',
        warn: 'bg-[oklch(0.77_0.16_70/0.15)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]',
    }[tone];

    return (
        <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${styles}`}>
            {children}
        </span>
    );
}
