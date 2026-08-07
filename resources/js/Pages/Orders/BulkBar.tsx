import { Button } from '@/components/ui/button';
import { Ban, Loader2, RefreshCw, RotateCcw, X } from 'lucide-react';
import { OrderAction, OrderStatusGroup } from './types';

/**
 * The bulk action bar, floating over the table once anything is ticked.
 *
 * It offers every action rather than only the ones that apply to the whole
 * selection: the server skips orders an action does not fit and reports how
 * many it left alone, which is friendlier than greying out "Refill" because
 * one row in fifty is still processing.
 */
export default function BulkBar({
    count,
    totalMatching,
    allSelected,
    limits,
    statusLabels,
    pending,
    onAction,
    onSelectAllMatching,
    onClear,
}: {
    count: number;
    totalMatching: number;
    allSelected: boolean;
    limits: { default: number; panel: number };
    statusLabels: Record<OrderStatusGroup, string>;
    pending: boolean;
    onAction: (action: OrderAction, status?: OrderStatusGroup) => void;
    onSelectAllMatching: () => void;
    onClear: () => void;
}) {
    if (count === 0) {
        return null;
    }

    const overPanelLimit = count > limits.panel;

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex justify-center px-4 pb-5">
            <div className="pointer-events-auto flex max-w-full flex-wrap items-center gap-3 rounded-xl border border-border bg-popover px-4 py-3 shadow-lg">
                <span className="text-sm font-semibold tabular-nums">
                    {count.toLocaleString('en-US')} selected
                </span>

                {/* The page's worth is ticked, but the filter matches more —
                    offer the rest rather than making them page through. */}
                {!allSelected && totalMatching > count && (
                    <button
                        type="button"
                        onClick={onSelectAllMatching}
                        className="text-sm text-primary underline-offset-4 hover:underline"
                    >
                        Select all {Math.min(totalMatching, limits.default).toLocaleString('en-US')} matching
                    </button>
                )}

                <div className="h-5 w-px bg-border" aria-hidden />

                <div className="flex flex-wrap items-center gap-1.5">
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={pending}
                        onClick={() => onAction('retry')}
                    >
                        <RotateCcw className="size-3.5" />
                        Retry
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={pending || overPanelLimit}
                        title={
                            overPanelLimit
                                ? `Refill calls the panel once per order, so it is capped at ${limits.panel}.`
                                : undefined
                        }
                        onClick={() => onAction('refill')}
                    >
                        <RefreshCw className="size-3.5" />
                        Refill
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={pending || overPanelLimit}
                        title={
                            overPanelLimit
                                ? `Cancel calls the panel once per order, so it is capped at ${limits.panel}.`
                                : undefined
                        }
                        onClick={() => onAction('cancel')}
                    >
                        <Ban className="size-3.5" />
                        Cancel
                    </Button>

                    <select
                        defaultValue=""
                        disabled={pending}
                        aria-label="Set status for the selection"
                        onChange={(event) => {
                            const value = event.target.value as OrderStatusGroup | '';

                            if (value) {
                                onAction('mark', value);
                                event.target.value = '';
                            }
                        }}
                        className="h-8 rounded-lg border border-border bg-background px-2 text-sm outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                    >
                        <option value="">Set status…</option>
                        {(
                            ['pending', 'processing', 'completed', 'failed'] as OrderStatusGroup[]
                        ).map((group) => (
                            <option key={group} value={group}>
                                {statusLabels[group]}
                            </option>
                        ))}
                    </select>
                </div>

                {pending && (
                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                )}

                <button
                    type="button"
                    onClick={onClear}
                    className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-accent"
                    aria-label="Clear selection"
                >
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}
