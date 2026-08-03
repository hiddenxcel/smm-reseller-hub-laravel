import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Filters, SortKey } from './types';

/**
 * Filter state, held in the URL.
 *
 * Same contract as the orders and customers pages — a filtered view is a
 * shareable URL, and each change is a partial visit so a catalogue of ten
 * thousand services does not re-render the sidebar or re-run the KPIs that
 * did not change.
 */
const RELOAD_ONLY = [
    'services',
    'filters',
    'isFiltered',
    'tabCounts',
    'kpis',
    'platforms',
    'categories',
];

type Patch = Partial<Filters> & { page?: number };

export function useServiceFilters(filters: Filters) {
    const [busy, setBusy] = useState(false);

    const apply = useCallback(
        (patch: Patch) => {
            const merged = { ...filters, ...patch };
            const next: Record<string, string | number> = {};

            if (merged.status) next.status = merged.status;
            if (merged.platform) next.platform = merged.platform;
            if (merged.category) next.category = merged.category;
            if (merged.panel) next.panel = merged.panel;
            if (merged.q) next.q = merged.q;
            if (merged.margin) next.margin = merged.margin;
            if (merged.featured) next.featured = 1;
            if (merged.min_price != null) next.min_price = merged.min_price;
            if (merged.max_price != null) next.max_price = merged.max_price;
            if (merged.sort !== 'name') next.sort = merged.sort;
            if (merged.dir !== 'asc') next.dir = merged.dir;
            if (merged.perPage !== 50) next.per_page = merged.perPage;

            if (patch.page !== undefined && patch.page > 1) {
                next.page = patch.page;
            }

            router.get(route('services.index'), next, {
                only: RELOAD_ONLY,
                preserveState: true,
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            });
        },
        [filters],
    );

    /** Same column flips direction; a new column starts ascending. */
    const sortBy = useCallback(
        (key: SortKey) => {
            const dir: 'asc' | 'desc' =
                filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc';

            apply({ sort: key, dir, page: 1 });
        },
        [apply, filters.sort, filters.dir],
    );

    const reset = useCallback(() => {
        router.get(
            route('services.index'),
            {},
            { only: RELOAD_ONLY, preserveState: true, preserveScroll: true },
        );
    }, []);

    return { apply, sortBy, reset, busy };
}

/**
 * The search box. Debounced — bound straight to the query string it would fire
 * a request per keystroke, each an ILIKE across the whole catalogue.
 */
export function useDebouncedSearch(
    initial: string | null,
    onSearch: (value: string) => void,
    delay = 350,
) {
    const [value, setValue] = useState(initial ?? '');
    const onSearchRef = useRef(onSearch);
    const isFirst = useRef(true);

    onSearchRef.current = onSearch;

    useEffect(() => {
        setValue(initial ?? '');
    }, [initial]);

    useEffect(() => {
        if (isFirst.current) {
            isFirst.current = false;

            return;
        }

        if (value === (initial ?? '')) {
            return;
        }

        const timer = setTimeout(() => onSearchRef.current(value), delay);

        return () => clearTimeout(timer);
        // `initial` is deliberately out of the deps: including it would cancel
        // the pending search the moment the server echoed the previous one.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value, delay]);

    return [value, setValue] as const;
}
