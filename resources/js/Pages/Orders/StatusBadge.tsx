import { STATUS_COLORS } from '@/components/charts/chart-tokens';
import { CheckCircle2, Clock, Loader2, XCircle } from 'lucide-react';
import { OrderStatusGroup } from './types';

/**
 * Status shown as a dot plus a word, never colour alone.
 *
 * Same reasoning as the dashboard's charts: `pending`'s amber sits below 3:1
 * on a light surface, so the icon and label are what carry the meaning and the
 * colour is reinforcement. There are four groups here against the charts'
 * three — `processing` is split out from `pending`, because "the panel has it"
 * and "the panel has not seen it" are different problems for a reseller.
 */
const TONE: Record<
    OrderStatusGroup,
    { color: string; icon: typeof Clock; tint: string }
> = {
    completed: {
        color: STATUS_COLORS.completed,
        icon: CheckCircle2,
        tint: 'bg-[#0ca30c]/10',
    },
    processing: {
        color: '#2563eb',
        icon: Loader2,
        tint: 'bg-[#2563eb]/10',
    },
    pending: {
        color: STATUS_COLORS.pending,
        icon: Clock,
        tint: 'bg-[#fab219]/15',
    },
    failed: {
        color: STATUS_COLORS.failed,
        icon: XCircle,
        tint: 'bg-[#d03b3b]/10',
    },
};

export default function StatusBadge({
    status,
    label,
    /** The panel's own wording, shown on hover when it differs from the group. */
    title,
}: {
    status: OrderStatusGroup;
    label: string;
    title?: string | null;
}) {
    const tone = TONE[status];
    const Icon = tone.icon;

    return (
        <span
            title={title ?? undefined}
            className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${tone.tint}`}
            style={{ color: tone.color }}
        >
            <Icon className="size-3 shrink-0" aria-hidden />
            {label}
        </span>
    );
}

/** Payment state, deliberately quieter than the fulfilment status. */
export function PaymentBadge({ status }: { status: string }) {
    const styles: Record<string, string> = {
        paid: 'bg-muted text-foreground',
        pending: 'bg-[#fab219]/15 text-[#8a5d00] dark:text-[#fab219]',
        failed: 'bg-[#d03b3b]/10 text-[#d03b3b]',
    };

    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize ${
                styles[status] ?? 'bg-muted text-muted-foreground'
            }`}
        >
            {status}
        </span>
    );
}
