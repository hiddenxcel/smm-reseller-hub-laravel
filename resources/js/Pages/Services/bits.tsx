import { CircleDot, EyeOff, PauseCircle, Star, TriangleAlert } from 'lucide-react';
import { ServiceStatus } from './types';

/**
 * The repeated pieces of the services screen.
 *
 * The margin indicator is the important one. A reseller scanning a thousand
 * rows needs to see at a glance which ones are losing money, and the answer
 * has to survive being printed in greyscale or read by someone colourblind —
 * so it is an icon and a number, with colour as reinforcement.
 */

const STATUS_STYLE: Record<
    ServiceStatus,
    { label: string; className: string; icon: typeof CircleDot; hint: string }
> = {
    active: {
        label: 'Active',
        className: 'bg-primary/10 text-primary',
        icon: CircleDot,
        hint: 'Customers can see and order this',
    },
    paused: {
        label: 'Paused',
        className:
            'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]',
        icon: PauseCircle,
        hint: 'Visible to customers, but not orderable',
    },
    hidden: {
        label: 'Hidden',
        className: 'bg-muted text-muted-foreground',
        icon: EyeOff,
        hint: 'Customers never see this',
    },
};

export function StatusBadge({
    status,
    autoPaused = false,
}: {
    status: ServiceStatus;
    autoPaused?: boolean;
}) {
    const style = STATUS_STYLE[status];
    const Icon = style.icon;

    return (
        <span
            // A pause the sync applied is worth distinguishing: it means the
            // panel dropped the service, not that the reseller chose this.
            title={autoPaused ? 'Paused automatically — the panel stopped listing it' : style.hint}
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.7rem] font-medium ${style.className}`}
        >
            <Icon className="size-3 shrink-0" aria-hidden />
            {style.label}
            {autoPaused && <span className="opacity-70">· auto</span>}
        </span>
    );
}

export function statusHint(status: ServiceStatus): string {
    return STATUS_STYLE[status].hint;
}

export function FeaturedStar({ featured }: { featured: boolean }) {
    if (!featured) {
        return null;
    }

    return (
        <Star
            className="size-3.5 shrink-0 fill-[oklch(0.77_0.16_70)] text-[oklch(0.77_0.16_70)]"
            aria-label="Featured"
        />
    );
}

/**
 * Margin as a number plus a tone.
 *
 * A service with no cost shows "—", not "0%": the panel never told us what it
 * costs, which is different from it making nothing, and a reseller chasing a
 * zero that is really an unknown wastes their afternoon.
 */
export function MarginCell({
    margin,
    underwater,
}: {
    margin: number | null;
    underwater: boolean;
}) {
    if (margin === null) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    if (underwater) {
        return (
            <span
                className="inline-flex items-center gap-1 font-medium text-destructive"
                title="Selling below what the panel charges you"
            >
                <TriangleAlert className="size-3 shrink-0" aria-hidden />
                {margin.toFixed(1)}%
            </span>
        );
    }

    const tone =
        margin < 10
            ? 'text-[oklch(0.55_0.13_70)] dark:text-[oklch(0.82_0.15_70)]'
            : 'text-foreground';

    return (
        <span className={tone} title={margin < 10 ? 'Thin margin' : undefined}>
            {margin.toFixed(1)}%
        </span>
    );
}

/**
 * Prices are quoted per 1,000 units and stored to four places, so a service
 * can legitimately cost 0.0009 — trimming to two would render several
 * different costs as "0.00".
 */
export function price(value: number | null, currency = 'USD'): string {
    if (value === null) {
        return '—';
    }

    const decimals = Math.abs(value) > 0 && Math.abs(value) < 0.01 ? 4 : 2;

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}

export function compact(value: number): string {
    if (Math.abs(value) < 1000) {
        return value.toLocaleString('en-US');
    }

    return new Intl.NumberFormat('en-US', {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

export function relativeTime(iso: string | null): string {
    if (!iso) {
        return 'Never';
    }

    const then = new Date(iso);
    const minutes = Math.round((Date.now() - then.getTime()) / 60000);

    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.round(hours / 24);
    if (days < 7) return `${days}d ago`;

    return then.toLocaleDateString('en-US', {
        day: 'numeric',
        month: 'short',
        year: then.getFullYear() === new Date().getFullYear() ? undefined : 'numeric',
    });
}

export function fullDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/** Why a price moved, in the words a reseller would use. */
export const PRICE_REASON: Record<string, string> = {
    manual: 'Edited by hand',
    bulk: 'Bulk change',
    rule: 'Markup rule',
    sync: 'Panel sync',
    import: 'Imported',
};

/** A quiet, deterministic tint per platform, for the sidebar dots. */
export function platformHue(platform: string): number {
    let hash = 0;

    for (let i = 0; i < platform.length; i++) {
        hash = (hash * 31 + platform.charCodeAt(i)) % 360;
    }

    return hash;
}
