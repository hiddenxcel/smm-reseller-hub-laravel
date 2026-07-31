import { CheckCircle2, Clock, LucideIcon, XCircle } from 'lucide-react';
import { MARKS, OrderStatus, STATUS_COLORS, STATUS_LABELS } from './chart-tokens';

type Props = {
    counts: Record<OrderStatus, number>;
};

/**
 * Order outcomes as a part-to-whole bar.
 *
 * These are the reserved status colours, not a categorical palette, so each
 * one is paired with an icon and a written label — `warning` sits below 3:1
 * on a light surface by design, and that pairing is what keeps the meaning
 * from resting on hue. The counts are listed underneath as well, so the bar
 * is never the only way to read them.
 */
const ICONS: Record<OrderStatus, LucideIcon> = {
    completed: CheckCircle2,
    pending: Clock,
    failed: XCircle,
};

const ORDER: OrderStatus[] = ['completed', 'pending', 'failed'];

export default function StatusMixBar({ counts }: Props) {
    const total = ORDER.reduce((sum, key) => sum + counts[key], 0);

    if (total === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No orders yet in the last 30 days.
            </p>
        );
    }

    const segments = ORDER.filter((key) => counts[key] > 0);

    return (
        <div>
            <div
                className="flex h-3 w-full overflow-hidden rounded-full"
                role="img"
                aria-label={ORDER.map(
                    (key) => `${STATUS_LABELS[key]}: ${counts[key]}`,
                ).join(', ')}
            >
                {segments.map((key, index) => (
                    <div
                        key={key}
                        className="h-full first:rounded-l-full last:rounded-r-full"
                        style={{
                            width: `${(counts[key] / total) * 100}%`,
                            background: STATUS_COLORS[key],
                            // A surface gap separates segments, never a stroke.
                            marginLeft: index === 0 ? 0 : MARKS.surfaceGap,
                        }}
                    />
                ))}
            </div>

            <ul className="mt-4 space-y-2">
                {ORDER.map((key) => {
                    const Icon = ICONS[key];
                    const share = total > 0 ? Math.round((counts[key] / total) * 100) : 0;

                    return (
                        <li key={key} className="flex items-center gap-2.5 text-sm">
                            <Icon
                                className="size-4 shrink-0"
                                style={{ color: STATUS_COLORS[key] }}
                            />
                            <span className="flex-1">{STATUS_LABELS[key]}</span>
                            <span className="text-muted-foreground [font-variant-numeric:tabular-nums]">
                                {counts[key].toLocaleString('en-US')}
                            </span>
                            <span className="w-10 text-right text-muted-foreground [font-variant-numeric:tabular-nums]">
                                {share}%
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
