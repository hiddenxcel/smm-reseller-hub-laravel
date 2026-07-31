import { ArrowDownRight, ArrowUpRight, LucideIcon, Minus } from 'lucide-react';
import { ReactNode } from 'react';

type Props = {
    label: string;
    value: string;
    /** Signed percentage against the previous equal-length period. */
    delta?: number | null;
    /** Most metrics are better when they rise; some (refunds) are not. */
    upIsGood?: boolean;
    comparisonLabel?: string;
    icon?: LucideIcon;
    /** 12-ish points, de-emphasised — context, not a second chart. */
    sparkline?: number[];
    footer?: ReactNode;
};

/**
 * A headline number. Stat tiles are the right form for a handful of KPIs —
 * a grouped bar chart of four unrelated measures says less and takes more
 * room.
 *
 * The value uses the font's proportional figures: tabular-nums is for columns
 * that must align, and makes a large standalone number look loose.
 */
export default function StatTile({
    label,
    value,
    delta,
    upIsGood = true,
    comparisonLabel = 'vs previous 30 days',
    icon: Icon,
    sparkline,
    footer,
}: Props) {
    const hasDelta = delta !== null && delta !== undefined;
    const isFlat = hasDelta && delta === 0;
    const isGood = hasDelta && (delta > 0) === upIsGood;

    const DeltaIcon = isFlat ? Minus : delta! > 0 ? ArrowUpRight : ArrowDownRight;

    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm text-muted-foreground">{label}</p>
                {Icon && <Icon className="size-4 shrink-0 text-muted-foreground" />}
            </div>

            <p className="font-heading mt-2 text-3xl font-extrabold tracking-tight">{value}</p>

            {hasDelta && (
                <p className="mt-1.5 flex items-center gap-1 text-xs">
                    <span
                        className={
                            isFlat
                                ? 'flex items-center gap-1 text-muted-foreground'
                                : isGood
                                  ? 'flex items-center gap-1 text-[#006300] dark:text-[#0ca30c]'
                                  : 'flex items-center gap-1 text-destructive'
                        }
                    >
                        <DeltaIcon className="size-3.5" />
                        {Math.abs(delta!).toFixed(1)}%
                    </span>
                    <span className="text-muted-foreground">{comparisonLabel}</span>
                </p>
            )}

            {sparkline && sparkline.length > 1 && <Sparkline points={sparkline} />}

            {footer && <div className="mt-3">{footer}</div>}
        </div>
    );
}

/** Trend as texture, not as a chart to read values off. */
function Sparkline({ points }: { points: number[] }) {
    const max = Math.max(...points, 1);
    const width = 120;
    const height = 28;

    const path = points
        .map((point, index) => {
            const x = (index / (points.length - 1)) * width;
            const y = height - (point / max) * height;

            return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
        })
        .join(' ');

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            className="mt-3 h-7 w-full"
            preserveAspectRatio="none"
            aria-hidden
        >
            <path
                d={path}
                fill="none"
                stroke="var(--color-chart-1)"
                strokeWidth={2}
                strokeLinecap="round"
                strokeLinejoin="round"
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}
