import { Button } from '@/components/ui/button';
import { ChevronDown, Search, SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';
import { Filters, OrderStatusGroup } from './types';
import { useDebouncedSearch } from './useOrderFilters';

/** Ranges a reseller actually asks for, rather than a date picker by default. */
const RANGES: Array<{ label: string; days: number | null }> = [
    { label: 'Today', days: 0 },
    { label: 'Last 7 days', days: 6 },
    { label: 'Last 30 days', days: 29 },
    { label: 'Last 90 days', days: 89 },
    { label: 'All time', days: null },
];

function isoDaysAgo(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return date.toISOString().slice(0, 10);
}

export default function FilterBar({
    filters,
    tabCounts,
    statusLabels,
    panels,
    isFiltered,
    onApply,
    onReset,
}: {
    filters: Filters;
    tabCounts?: Record<'all' | OrderStatusGroup, number>;
    statusLabels: Record<OrderStatusGroup, string>;
    panels: Array<{ id: number; name: string }>;
    isFiltered: boolean;
    onApply: (patch: Partial<Filters> & { page?: number }) => void;
    onReset: () => void;
}) {
    const [advancedOpen, setAdvancedOpen] = useState(
        filters.payment !== null || filters.panel !== null || filters.from !== null,
    );

    const [search, setSearch] = useDebouncedSearch(filters.q, (value) =>
        onApply({ q: value || null, page: 1 }),
    );

    const tabs: Array<{ key: 'all' | OrderStatusGroup; label: string }> = [
        { key: 'all', label: 'All' },
        { key: 'pending', label: statusLabels.pending },
        { key: 'processing', label: statusLabels.processing },
        { key: 'completed', label: statusLabels.completed },
        { key: 'failed', label: statusLabels.failed },
    ];

    const activeRange = RANGES.find((range) =>
        range.days === null
            ? filters.from === null
            : filters.from === isoDaysAgo(range.days),
    );

    return (
        <div className="space-y-3">
            {/* Status tabs. Counts land a moment after the table — the number
                is useful, but not worth holding the rows back for. */}
            <div
                className="-mb-px flex gap-1 overflow-x-auto"
                role="tablist"
                aria-label="Filter by status"
            >
                {tabs.map((tab) => {
                    const isActive =
                        tab.key === 'all'
                            ? filters.status === null
                            : filters.status === tab.key;
                    const count = tabCounts?.[tab.key];

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={isActive}
                            onClick={() =>
                                onApply({
                                    status: tab.key === 'all' ? null : tab.key,
                                    page: 1,
                                })
                            }
                            className={[
                                'flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm transition-colors',
                                isActive
                                    ? 'border-primary font-semibold text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ].join(' ')}
                        >
                            {tab.label}
                            {count !== undefined && (
                                <span
                                    className={[
                                        'rounded-full px-1.5 py-px text-[0.7rem] tabular-nums',
                                        isActive
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground',
                                    ].join(' ')}
                                >
                                    {count.toLocaleString('en-US')}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-0 flex-1 sm:max-w-xs">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Phone, service, order ID…"
                        aria-label="Search orders"
                        className="h-9 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                    />
                </div>

                <Select
                    label="Range"
                    value={activeRange?.label ?? 'Custom'}
                    onChange={(label) => {
                        const range = RANGES.find((item) => item.label === label);

                        if (!range) return;

                        onApply({
                            from: range.days === null ? null : isoDaysAgo(range.days),
                            to: null,
                            page: 1,
                        });
                    }}
                    options={RANGES.map((range) => range.label)}
                />

                <Button
                    type="button"
                    variant={advancedOpen ? 'secondary' : 'outline'}
                    size="sm"
                    onClick={() => setAdvancedOpen((open) => !open)}
                    className="h-9"
                >
                    <SlidersHorizontal className="size-3.5" />
                    Filters
                    <ChevronDown
                        className={`size-3.5 transition-transform ${advancedOpen ? 'rotate-180' : ''}`}
                    />
                </Button>

                {isFiltered && (
                    <button
                        type="button"
                        onClick={onReset}
                        className="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <X className="size-3.5" />
                        Clear
                    </button>
                )}

                <div className="ms-auto">
                    <a
                        href={route('orders.export', currentQuery())}
                        className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm transition-colors hover:bg-accent"
                    >
                        Export CSV
                    </a>
                </div>
            </div>

            {advancedOpen && (
                <div className="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-muted/30 p-3">
                    <Select
                        label="Payment"
                        value={filters.payment ?? 'Any'}
                        onChange={(value) =>
                            onApply({
                                payment: value === 'Any' ? null : value.toLowerCase(),
                                page: 1,
                            })
                        }
                        options={['Any', 'Paid', 'Pending', 'Failed']}
                    />

                    {panels.length > 0 && (
                        <Select
                            label="Panel"
                            value={
                                panels.find((panel) => panel.id === filters.panel)?.name ??
                                'Any'
                            }
                            onChange={(value) =>
                                onApply({
                                    panel:
                                        panels.find((panel) => panel.name === value)?.id ??
                                        null,
                                    page: 1,
                                })
                            }
                            options={['Any', ...panels.map((panel) => panel.name)]}
                        />
                    )}

                    <DateField
                        label="From"
                        value={filters.from}
                        onChange={(value) => onApply({ from: value, page: 1 })}
                    />
                    <DateField
                        label="To"
                        value={filters.to}
                        onChange={(value) => onApply({ to: value, page: 1 })}
                    />
                </div>
            )}
        </div>
    );
}

/** The current query string, so Export downloads exactly what is on screen. */
function currentQuery(): Record<string, string> {
    const params = new URLSearchParams(window.location.search);
    params.delete('page');

    return Object.fromEntries(params.entries());
}

function Select({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: string[];
    onChange: (value: string) => void;
}) {
    return (
        <label className="flex flex-col gap-1">
            <span className="sr-only text-xs text-muted-foreground sm:not-sr-only">
                {label}
            </span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-label={label}
                className="h-9 rounded-lg border border-border bg-background px-2.5 text-sm outline-none transition-colors focus:border-ring focus:ring-[3px] focus:ring-ring/30"
            >
                {!options.includes(value) && <option>{value}</option>}
                {options.map((option) => (
                    <option key={option}>{option}</option>
                ))}
            </select>
        </label>
    );
}

function DateField({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string | null;
    onChange: (value: string | null) => void;
}) {
    return (
        <label className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">{label}</span>
            <input
                type="date"
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value || null)}
                className="h-9 rounded-lg border border-border bg-background px-2.5 text-sm outline-none transition-colors focus:border-ring focus:ring-[3px] focus:ring-ring/30"
            />
        </label>
    );
}
