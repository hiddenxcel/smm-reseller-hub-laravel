import { Button } from '@/components/ui/button';
import { ChevronDown, Search, SlidersHorizontal, Star, X } from 'lucide-react';
import { useState } from 'react';
import { Filters, MarginBand, ServiceStatus, ServicesPageProps } from './types';
import { useDebouncedSearch } from './useServiceFilters';

/** Margin bands, worded as a decision rather than a number. */
const MARGIN_LABELS: Record<MarginBand, string> = {
    loss: 'Losing money',
    thin: 'Thin (under 10%)',
    healthy: 'Healthy (10–40%)',
    high: 'High (over 40%)',
    unknown: 'No cost known',
};

export default function FilterBar({
    filters,
    tabCounts,
    panels,
    isFiltered,
    onApply,
    onReset,
}: {
    filters: Filters;
    tabCounts?: ServicesPageProps['tabCounts'];
    panels: Array<{ id: number; name: string }>;
    isFiltered: boolean;
    onApply: (patch: Partial<Filters> & { page?: number }) => void;
    onReset: () => void;
}) {
    const [advancedOpen, setAdvancedOpen] = useState(
        filters.panel !== null ||
            filters.margin !== null ||
            filters.min_price !== null ||
            filters.max_price !== null,
    );

    const [search, setSearch] = useDebouncedSearch(filters.q, (value) =>
        onApply({ q: value || null, page: 1 }),
    );

    const tabs: Array<{ key: 'all' | ServiceStatus; label: string }> = [
        { key: 'all', label: 'All' },
        { key: 'active', label: 'Active' },
        { key: 'paused', label: 'Paused' },
        { key: 'hidden', label: 'Hidden' },
    ];

    return (
        <div className="space-y-3">
            <div
                className="-mb-px flex gap-1 overflow-x-auto"
                role="tablist"
                aria-label="Filter by status"
            >
                {tabs.map((tab) => {
                    const isActive =
                        tab.key === 'all' ? filters.status === null : filters.status === tab.key;
                    const count = tabCounts?.[tab.key];

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={isActive}
                            onClick={() =>
                                onApply({ status: tab.key === 'all' ? null : tab.key, page: 1 })
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
                                        'font-data rounded-full px-1.5 py-px text-[0.7rem] tabular-nums',
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

            <div className="flex items-center gap-2">
                <div className="relative min-w-0 flex-1 sm:max-w-sm">
                    <Search
                        className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search services"
                        aria-label="Search services"
                        className="h-9 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                    />
                </div>

                <Button
                    type="button"
                    variant={filters.featured ? 'secondary' : 'outline'}
                    size="sm"
                    className="h-9"
                    onClick={() => onApply({ featured: !filters.featured, page: 1 })}
                >
                    <Star
                        className={`size-3.5 ${filters.featured ? 'fill-current' : ''}`}
                        aria-hidden
                    />
                    <span className="hidden sm:inline">Featured</span>
                </Button>

                <Button
                    type="button"
                    variant={advancedOpen ? 'secondary' : 'outline'}
                    size="sm"
                    className="h-9"
                    onClick={() => setAdvancedOpen((open) => !open)}
                >
                    <SlidersHorizontal className="size-3.5" />
                    <span className="hidden sm:inline">Filters</span>
                    <ChevronDown
                        className={`size-3.5 transition-transform ${advancedOpen ? 'rotate-180' : ''}`}
                        aria-hidden
                    />
                </Button>

                {isFiltered && (
                    <button
                        type="button"
                        onClick={onReset}
                        className="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <X className="size-3.5" aria-hidden />
                        Clear
                    </button>
                )}
            </div>

            {advancedOpen && (
                <div className="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-muted/30 p-3">
                    <Select
                        label="Margin"
                        value={filters.margin ? MARGIN_LABELS[filters.margin] : 'Any'}
                        options={['Any', ...Object.values(MARGIN_LABELS)]}
                        onChange={(value) => {
                            const key = (Object.keys(MARGIN_LABELS) as MarginBand[]).find(
                                (candidate) => MARGIN_LABELS[candidate] === value,
                            );

                            onApply({ margin: key ?? null, page: 1 });
                        }}
                    />

                    {panels.length > 0 && (
                        <Select
                            label="Provider"
                            value={panels.find((p) => p.id === filters.panel)?.name ?? 'All'}
                            options={['All', ...panels.map((panel) => panel.name)]}
                            onChange={(value) =>
                                onApply({
                                    panel: panels.find((p) => p.name === value)?.id ?? null,
                                    page: 1,
                                })
                            }
                        />
                    )}

                    <NumberField
                        label="Price from"
                        value={filters.min_price}
                        onChange={(value) => onApply({ min_price: value, page: 1 })}
                    />
                    <NumberField
                        label="Price to"
                        value={filters.max_price}
                        onChange={(value) => onApply({ max_price: value, page: 1 })}
                    />
                </div>
            )}
        </div>
    );
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
            <span className="text-xs text-muted-foreground">{label}</span>
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

function NumberField({
    label,
    value,
    onChange,
}: {
    label: string;
    value: number | null;
    onChange: (value: number | null) => void;
}) {
    return (
        <label className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">{label}</span>
            <input
                type="number"
                min="0"
                step="0.01"
                value={value ?? ''}
                onChange={(event) =>
                    onChange(event.target.value === '' ? null : Number(event.target.value))
                }
                className="font-data h-9 w-28 rounded-lg border border-border bg-background px-2.5 text-sm outline-none transition-colors focus:border-ring focus:ring-[3px] focus:ring-ring/30"
            />
        </label>
    );
}
