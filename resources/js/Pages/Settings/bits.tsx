import { AlertCircle } from 'lucide-react';
import { ReactNode } from 'react';

/**
 * Pieces shared across the settings tabs.
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
        <div className={`flex items-start gap-2 rounded-lg border px-4 py-3 text-sm ${styles}`}>
            <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden />
            <div>{children}</div>
        </div>
    );
}

export function EmptyState({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}
