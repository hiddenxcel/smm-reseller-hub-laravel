/**
 * Chart tokens, and the rules that go with them.
 *
 * Single-series charts use the brand's chart-1. Order status is NOT a
 * categorical palette — it is state, so it uses the reserved status colours,
 * and every status mark ships an icon and a text label beside it. That
 * pairing is the mitigation for `warning` sitting below 3:1 on a light
 * surface, so it is not optional decoration.
 *
 * Validated with the dataviz validator at three status slots, both modes:
 * CVD separation ΔE 11.3 (protan), normal-vision ΔE 27.6 — both clear.
 */

export const STATUS_COLORS = {
    completed: '#0ca30c',
    pending: '#fab219',
    failed: '#d03b3b',
} as const;

export type OrderStatus = keyof typeof STATUS_COLORS;

export const STATUS_LABELS: Record<OrderStatus, string> = {
    completed: 'Completed',
    pending: 'In progress',
    failed: 'Failed',
};

/** Mark specs from the dataviz skill, kept in one place so they stay uniform. */
export const MARKS = {
    /** Bars never fill their slot — the leftover band is deliberate air. */
    maxBarWidth: 24,
    /** Rounded at the data end, square at the baseline. */
    barRadius: 4,
    lineWidth: 2,
    markerRadius: 4,
    /** White doing the separating, rather than a stroke around each mark. */
    surfaceGap: 2,
    areaFillOpacity: 0.1,
} as const;

export function formatMoney(value: number, currency = 'USD'): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        maximumFractionDigits: value >= 1000 ? 0 : 2,
    }).format(value);
}

/** 1,284 / 12.9K / 4.2M — stat tiles stay readable at a glance. */
export function formatCompact(value: number): string {
    if (Math.abs(value) < 1000) {
        return value.toLocaleString('en-US');
    }

    return new Intl.NumberFormat('en-US', {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

export function formatDay(iso: string): string {
    return new Date(`${iso}T00:00:00`).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

/** Round an axis maximum up to something a reader can hold in their head. */
export function niceCeiling(value: number): number {
    if (value <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));
    const normalised = value / magnitude;
    const step = normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10;

    return step * magnitude;
}
