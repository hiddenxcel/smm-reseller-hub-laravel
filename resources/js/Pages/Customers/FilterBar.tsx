import { Button } from '@/components/ui/button';
import { ChevronDown, Search, SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';
import { CustomersPageProps, Filters, Segment } from './types';
import { useDebouncedSearch } from './useCustomerFilters';

/** Wallet bands, worded as a reseller would ask for them. */
const WALLET_LABELS: Record<string, string> = {
    empty: 'Empty',
    low: 'Under 5',
    funded: '5 – 50',
    high: 'Over 50',
};

const BOT_LABELS: Record<string, string> = {
    order: 'Order Bot',
    support: 'Support Bot',
    both: 'Both bots',
};

export default function FilterBar({
    filters,
    tabCounts,
    options,
    isFiltered,
    onApply,
    onReset,
}: {
    filters: Filters;
    tabCounts?: CustomersPageProps['tabCounts'];
    options?: CustomersPageProps['options'];
    isFiltered: boolean;
    onApply: (patch: Partial<Filters> & { page?: number }) => void;
    onReset: () => void;
}) {
    const [advancedOpen, setAdvancedOpen] = useState(
        filters.bot !== null ||
            filters.country !== null ||
            filters.tag !== null ||
            filters.wallet !== null ||
            filters.from !== null,
    );

    const [search, setSearch] = useDebouncedSearch(filters.q, (value) =>
        onApply({ q: value || null, page: 1 }),
    );

    const tabs: Array<{ key: 'all' | Segment; label: string }> = [
        { key: 'all', label: 'All' },
        { key: 'vip', label: 'VIP' },
        { key: 'active', label: 'Active' },
        { key: 'new', label: 'New' },
        { key: 'blocked', label: 'Blocked' },
    ];

    return (
        <div className="space-y-3">
            <div
                className="-mb-px flex gap-1 overflow-x-auto"
                role="tablist"
                aria-label="Filter customers"
            >
                {tabs.map((tab) => {
                    const isActive =
                        tab.key === 'all'
                            ? filters.segment === null
                            : filters.segment === tab.key;
                    const count = tabCounts?.[tab.key];

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={isActive}
                            onClick={() =>
                                onApply({
                                    segment: tab.key === 'all' ? null : tab.key,
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

            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-0 flex-1 sm:max-w-sm">
                    <Search
                        className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Name, phone, email, referral code…"
                        aria-label="Search customers"
                        className="h-9 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                    />
                </div>

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
                        label="Bot"
                        value={filters.bot ? BOT_LABELS[filters.bot] : 'Any'}
                        options={['Any', ...Object.values(BOT_LABELS)]}
                        onChange={(value) => {
                            const key = Object.keys(BOT_LABELS).find(
                                (candidate) => BOT_LABELS[candidate] === value,
                            );

                            onApply({ bot: (key as Filters['bot']) ?? null, page: 1 });
                        }}
                    />

                    <Select
                        label="Wallet"
                        value={filters.wallet ? WALLET_LABELS[filters.wallet] : 'Any'}
                        options={['Any', ...Object.values(WALLET_LABELS)]}
                        onChange={(value) => {
                            const key = Object.keys(WALLET_LABELS).find(
                                (candidate) => WALLET_LABELS[candidate] === value,
                            );

                            onApply({ wallet: key ?? null, page: 1 });
                        }}
                    />

                    {/* Country and tag only appear once the reseller has
                        actually set them on someone — an empty dropdown is a
                        dead control. */}
                    {options && options.countries.length > 0 && (
                        <Select
                            label="Country"
                            value={filters.country ?? 'Any'}
                            options={['Any', ...options.countries]}
                            onChange={(value) =>
                                onApply({ country: value === 'Any' ? null : value, page: 1 })
                            }
                        />
                    )}

                    {options && options.tags.length > 0 && (
                        <Select
                            label="Tag"
                            value={filters.tag ?? 'Any'}
                            options={['Any', ...options.tags]}
                            onChange={(value) =>
                                onApply({ tag: value === 'Any' ? null : value, page: 1 })
                            }
                        />
                    )}

                    <DateField
                        label="Joined from"
                        value={filters.from}
                        onChange={(value) => onApply({ from: value, page: 1 })}
                    />
                    <DateField
                        label="Joined to"
                        value={filters.to}
                        onChange={(value) => onApply({ to: value, page: 1 })}
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
