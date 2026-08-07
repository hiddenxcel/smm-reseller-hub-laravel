import { ReactNode } from 'react';
import { BotStatus } from './types';

/**
 * Pieces shared across the bot's tabs.
 *
 * The status pill is the one that matters: a reseller opening this page is
 * usually asking "is my bot answering?", and that question deserves an answer
 * before they read anything else.
 */

export function StatusPill({ status }: { status: BotStatus }) {
    const { live, connected, subscription } = status;

    // Colour alone would fail anyone who cannot see it, so the words carry the
    // meaning and the dot only reinforces it.
    const tone = live
        ? 'bg-primary/10 text-primary'
        : 'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]';

    const label = !connected
        ? 'No number connected'
        : subscription === 'inactive'
          ? 'Subscription inactive'
          : live
            ? subscription === 'sandbox'
                ? 'Sandbox — test numbers only'
                : 'Online'
            : 'Offline';

    return (
        <div className="flex flex-col items-start gap-1 sm:items-end">
            <span
                className={`inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium ${tone}`}
            >
                <span
                    className={`size-2 rounded-full ${live ? 'bg-primary' : 'bg-current'}`}
                    aria-hidden
                />
                {label}
            </span>

            {/* On its own line and labelled: bolted onto the end of "Subscription
                inactive" it read as though the number were the problem. */}
            {status.number && (
                <span className="text-xs text-muted-foreground">
                    on <span className="font-data">{status.number}</span>
                </span>
            )}
        </div>
    );
}

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
        <section className="rounded-xl border border-border bg-card p-5">
            <h2 className="font-heading font-bold">{title}</h2>
            {description && (
                <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
            )}
            <div className="mt-4">{children}</div>
            {footer && <div className="mt-4 border-t border-border pt-4">{footer}</div>}
        </section>
    );
}

export function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
            <p className="font-data mt-1 text-2xl font-semibold">{value}</p>
        </div>
    );
}

export function Toggle({
    label,
    description,
    checked,
    onChange,
}: {
    label: string;
    description?: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <label className="flex cursor-pointer items-start gap-3 py-2">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-0.5 size-4 shrink-0 rounded border-input text-primary focus:ring-ring"
            />
            <span className="min-w-0">
                <span className="block text-sm font-medium">{label}</span>
                {description && (
                    <span className="block text-sm text-muted-foreground">{description}</span>
                )}
            </span>
        </label>
    );
}

export function Field({
    label,
    hint,
    error,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <label className="block">
            <span className="block text-sm font-medium">{label}</span>
            {hint && <span className="block text-xs text-muted-foreground">{hint}</span>}
            <div className="mt-1.5">{children}</div>
            {error && <span className="mt-1 block text-xs text-destructive">{error}</span>}
        </label>
    );
}

export const inputClass =
    'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring';
