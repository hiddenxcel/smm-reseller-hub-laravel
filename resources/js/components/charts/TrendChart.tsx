import { useEffect, useId, useMemo, useState } from 'react';
import { MARKS, formatDay, niceCeiling } from './chart-tokens';

export type TrendPoint = {
    date: string;
    value: number;
};

type Props = {
    points: TrendPoint[];
    /** 'area' for money over time, 'column' for counts. */
    variant?: 'area' | 'column';
    formatValue: (value: number) => string;
    /** Names what is plotted, so a single series needs no legend box. */
    label: string;
};

// The drawing is scaled to the card, so on a phone a 720-wide drawing shrinks
// its labels to a size nobody can read. A narrower, taller drawing keeps text
// close to its real size there.
const VIEWBOX_WIDE = { width: 720, height: 220 };
const VIEWBOX_NARROW = { width: 360, height: 250 };

function useCompact(): boolean {
    const [compact, setCompact] = useState(false);

    useEffect(() => {
        const query = window.matchMedia('(max-width: 639px)');
        const update = () => setCompact(query.matches);

        update();
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return compact;
}
const PADDING = { top: 16, right: 16, bottom: 28, left: 48 };

/**
 * One series over time. Because there is only one series there is no legend
 * and no categorical palette — the card's title says what is plotted, and the
 * hue is the brand's chart-1.
 *
 * Values are reachable three ways: the axis, the hover tooltip, and the table
 * view. A tooltip is never the only way to read a number.
 */
export default function TrendChart({ points, variant = 'area', formatValue, label }: Props) {
    const gradientId = useId();
    const [hovered, setHovered] = useState<number | null>(null);
    const compact = useCompact();
    const VIEWBOX = compact ? VIEWBOX_NARROW : VIEWBOX_WIDE;

    const plotWidth = VIEWBOX.width - PADDING.left - PADDING.right;
    const plotHeight = VIEWBOX.height - PADDING.top - PADDING.bottom;

    const max = useMemo(
        () => niceCeiling(Math.max(...points.map((point) => point.value), 0)),
        [points],
    );

    const xFor = (index: number) =>
        points.length === 1
            ? PADDING.left + plotWidth / 2
            : PADDING.left + (index / (points.length - 1)) * plotWidth;

    const yFor = (value: number) => PADDING.top + plotHeight - (value / max) * plotHeight;

    const linePath = points
        .map((point, index) => `${index === 0 ? 'M' : 'L'} ${xFor(index)} ${yFor(point.value)}`)
        .join(' ');

    const areaPath = `${linePath} L ${xFor(points.length - 1)} ${PADDING.top + plotHeight} L ${xFor(0)} ${PADDING.top + plotHeight} Z`;

    // Four gridlines is enough to read against without becoming a ladder.
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((fraction) => ({
        value: max * fraction,
        y: PADDING.top + plotHeight - fraction * plotHeight,
    }));

    const bandWidth = plotWidth / Math.max(points.length, 1);
    const barWidth = Math.min(MARKS.maxBarWidth, bandWidth - MARKS.surfaceGap * 2);

    const peakIndex = points.reduce(
        (best, point, index) => (point.value > points[best].value ? index : best),
        0,
    );

    const active = hovered !== null ? points[hovered] : null;

    return (
        <figure className="m-0">
            <div className="relative">
                <svg
                    viewBox={`0 0 ${VIEWBOX.width} ${VIEWBOX.height}`}
                    className="w-full"
                    role="img"
                    aria-label={`${label} over the last ${points.length} days`}
                    onMouseLeave={() => setHovered(null)}
                >
                    <defs>
                        <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                            <stop
                                offset="0%"
                                stopColor="var(--color-chart-1)"
                                stopOpacity={MARKS.areaFillOpacity * 2}
                            />
                            <stop offset="100%" stopColor="var(--color-chart-1)" stopOpacity={0} />
                        </linearGradient>
                    </defs>

                    {/* Solid hairlines, one step off the surface — never dashed. */}
                    {ticks.map((tick) => (
                        <g key={tick.y}>
                            <line
                                x1={PADDING.left}
                                y1={tick.y}
                                x2={VIEWBOX.width - PADDING.right}
                                y2={tick.y}
                                stroke="var(--color-border)"
                                strokeWidth={1}
                            />
                            <text
                                x={PADDING.left - 10}
                                y={tick.y + 4}
                                textAnchor="end"
                                className="fill-muted-foreground text-[11px] [font-variant-numeric:tabular-nums]"
                            >
                                {formatValue(tick.value)}
                            </text>
                        </g>
                    ))}

                    {variant === 'area' ? (
                        <>
                            <path d={areaPath} fill={`url(#${gradientId})`} />
                            <path
                                d={linePath}
                                fill="none"
                                stroke="var(--color-chart-1)"
                                strokeWidth={MARKS.lineWidth}
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            />
                        </>
                    ) : (
                        points.map((point, index) => {
                            const height = Math.max(
                                (point.value / max) * plotHeight,
                                point.value > 0 ? 2 : 0,
                            );

                            return (
                                <rect
                                    key={point.date}
                                    x={xFor(index) - barWidth / 2}
                                    y={PADDING.top + plotHeight - height}
                                    width={barWidth}
                                    height={height}
                                    rx={MARKS.barRadius}
                                    fill="var(--color-chart-1)"
                                    opacity={hovered === null || hovered === index ? 1 : 0.45}
                                />
                            );
                        })
                    )}

                    {/* The peak is the one point worth labelling — a number on
                        every point goes unread. */}
                    {points[peakIndex].value > 0 && (
                        <text
                            x={xFor(peakIndex)}
                            y={yFor(points[peakIndex].value) - 12}
                            textAnchor="middle"
                            className="fill-foreground text-[11px] font-semibold"
                        >
                            {formatValue(points[peakIndex].value)}
                        </text>
                    )}

                    {variant === 'area' && (
                        <circle
                            cx={xFor(points.length - 1)}
                            cy={yFor(points[points.length - 1].value)}
                            r={MARKS.markerRadius}
                            fill="var(--color-chart-1)"
                            stroke="var(--color-card)"
                            strokeWidth={MARKS.surfaceGap}
                        />
                    )}

                    {/* Crosshair on the hovered day. */}
                    {hovered !== null && (
                        <line
                            x1={xFor(hovered)}
                            y1={PADDING.top}
                            x2={xFor(hovered)}
                            y2={PADDING.top + plotHeight}
                            stroke="var(--color-muted-foreground)"
                            strokeWidth={1}
                        />
                    )}

                    {/* Hit areas are the full band, not the mark — a 2px line
                        is impossible to land on. */}
                    {points.map((point, index) => (
                        <rect
                            key={`hit-${point.date}`}
                            x={xFor(index) - bandWidth / 2}
                            y={PADDING.top}
                            width={bandWidth}
                            height={plotHeight}
                            fill="transparent"
                            onMouseEnter={() => setHovered(index)}
                        />
                    ))}

                    {/* Every third day (every fourth on a phone, counted back
                        from today), so labels never collide. */}
                    {points.map((point, index) =>
                        (compact
                            ? (points.length - 1 - index) % 4 === 0
                            : index % 3 === 0 || index === points.length - 1) ? (
                            <text
                                key={`x-${point.date}`}
                                x={xFor(index)}
                                y={VIEWBOX.height - 8}
                                textAnchor="middle"
                                className="fill-muted-foreground text-[11px]"
                            >
                                {formatDay(point.date)}
                            </text>
                        ) : null,
                    )}
                </svg>

                {active && (
                    <div
                        className="pointer-events-none absolute top-2 rounded-lg border border-border bg-popover px-2.5 py-1.5 text-xs shadow-sm"
                        style={{
                            left: `${(xFor(hovered!) / VIEWBOX.width) * 100}%`,
                            transform: 'translateX(-50%)',
                        }}
                    >
                        <span className="block font-medium">{formatDay(active.date)}</span>
                        <span className="flex items-center gap-1.5 text-muted-foreground">
                            <span
                                className="size-2 rounded-full"
                                style={{ background: 'var(--color-chart-1)' }}
                                aria-hidden
                            />
                            {formatValue(active.value)}
                        </span>
                    </div>
                )}
            </div>
        </figure>
    );
}
