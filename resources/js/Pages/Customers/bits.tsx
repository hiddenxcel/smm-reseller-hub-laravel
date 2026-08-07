import { Ban, Crown, LifeBuoy, ShoppingBag, Sparkles } from 'lucide-react';
import { BotType, Segment } from './types';

/**
 * The small repeated pieces: avatar, segment chips, bot chips, and the
 * date/number formatting the table and the slide-over share.
 *
 * Kept together because they must look identical in both places — a customer's
 * initials rendering one way in the row and another in the panel is the kind
 * of small inconsistency that makes a product feel unfinished.
 */

/**
 * Initials on a colour derived from the phone number.
 *
 * Deterministic rather than random: the same person keeps the same colour on
 * every visit, which is what makes an avatar useful for scanning a list. The
 * hue comes from the phone because it is the one field every customer has —
 * names are frequently blank.
 */
export function Avatar({
    name,
    phone,
    size = 'md',
}: {
    name: string | null;
    phone: string;
    size?: 'sm' | 'md' | 'lg';
}) {
    const initials = initialsFor(name, phone);
    const hue = hueFor(phone);

    const dimensions = {
        sm: 'size-7 text-[0.65rem]',
        md: 'size-9 text-xs',
        lg: 'size-14 text-lg',
    }[size];

    return (
        <span
            aria-hidden
            className={`${dimensions} inline-flex shrink-0 items-center justify-center rounded-full font-semibold tracking-tight`}
            style={{
                // Low chroma so a wall of avatars stays quiet next to the
                // status chips, which are the colour that should carry meaning.
                backgroundColor: `oklch(0.90 0.05 ${hue})`,
                color: `oklch(0.40 0.10 ${hue})`,
            }}
        >
            {initials}
        </span>
    );
}

function initialsFor(name: string | null, phone: string): string {
    const trimmed = (name ?? '').trim();

    if (trimmed !== '') {
        const parts = trimmed.split(/\s+/).slice(0, 2);

        return parts.map((part) => part[0]?.toUpperCase() ?? '').join('');
    }

    // No name: the last two digits are what a reseller recognises a number by.
    return phone.replace(/\D/g, '').slice(-2) || '#';
}

function hueFor(phone: string): number {
    let hash = 0;

    for (let i = 0; i < phone.length; i++) {
        hash = (hash * 31 + phone.charCodeAt(i)) % 360;
    }

    return hash;
}

const SEGMENT_STYLE: Record<
    Segment,
    { label: string; className: string; icon?: typeof Crown }
> = {
    blocked: {
        label: 'Blocked',
        className: 'bg-destructive/10 text-destructive',
        icon: Ban,
    },
    vip: {
        label: 'VIP',
        className: 'bg-[oklch(0.77_0.16_70/0.16)] text-[oklch(0.45_0.13_70)] dark:text-[oklch(0.82_0.15_70)]',
        icon: Crown,
    },
    new: {
        label: 'New',
        className: 'bg-primary/10 text-primary',
        icon: Sparkles,
    },
    returning: {
        label: 'Returning',
        className: 'bg-muted text-muted-foreground',
    },
    active: {
        label: 'Active',
        className: 'bg-muted text-muted-foreground',
    },
};

/**
 * A customer's segments, most defining first.
 *
 * Only the leading one is shown in a table row: five chips per row turns the
 * column into noise, and `blocked` and `vip` are the two that change what a
 * reseller does next.
 */
export function SegmentChips({
    segments,
    limit,
}: {
    segments: Segment[];
    limit?: number;
}) {
    const shown = limit ? segments.slice(0, limit) : segments;

    return (
        <span className="inline-flex flex-wrap items-center gap-1">
            {shown.map((segment) => {
                const style = SEGMENT_STYLE[segment];
                const Icon = style.icon;

                return (
                    <span
                        key={segment}
                        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.7rem] font-medium ${style.className}`}
                    >
                        {Icon && <Icon className="size-3 shrink-0" aria-hidden />}
                        {style.label}
                    </span>
                );
            })}
        </span>
    );
}

/** Which bots this customer has actually talked to. */
export function BotChips({ bots }: { bots: BotType[] }) {
    if (bots.length === 0) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <span className="inline-flex items-center gap-1">
            {bots.includes('order') && (
                <span
                    title="Order Bot"
                    className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[0.7rem] text-muted-foreground"
                >
                    <ShoppingBag className="size-3" aria-hidden />
                    Order
                </span>
            )}
            {bots.includes('support') && (
                <span
                    title="Support Bot"
                    className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[0.7rem] text-muted-foreground"
                >
                    <LifeBuoy className="size-3" aria-hidden />
                    Support
                </span>
            )}
        </span>
    );
}

export function money(value: number, currency = 'USD'): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        maximumFractionDigits: Math.abs(value) >= 1000 ? 0 : 2,
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

/**
 * "3h ago", "12 Mar" — recency reads better as a distance than a date, until
 * it is far enough away that the date is the more useful fact.
 */
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

/** +255 712 345 678 — grouped so a long number can be read back aloud. */
export function prettyPhone(phone: string): string {
    const digits = phone.replace(/\D/g, '');

    if (digits.length < 9) {
        return phone;
    }

    const country = digits.slice(0, digits.length - 9);
    const rest = digits.slice(-9);

    return `+${country} ${rest.slice(0, 3)} ${rest.slice(3, 6)} ${rest.slice(6)}`;
}
