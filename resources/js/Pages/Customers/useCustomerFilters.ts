import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Filters, SortKey } from './types';

/**
 * Filter state, held in the URL rather than in React.
 *
 * Same contract as the orders page: a filtered view is a shareable, refreshable
 * URL, and every change is a partial Inertia visit so filtering a large book of
 * customers does not re-render the sidebar or re-run the KPI aggregates that
 * did not change.
 */
const RELOAD_ONLY = [
    'customers',
    'filters',
    'isFiltered',
    'tabCounts',
    'kpis',
    'options',
];

type Patch = Partial<Filters> & { page?: number };

export function useCustomerFilters(filters: Filters) {
    const [busy, setBusy] = useState(false);

    const apply = useCallback(
        (patch: Patch, options: { replace?: boolean } = {}) => {
            const merged = { ...filters, ...patch };
            const next: Record<string, string | number> = {};

            // Only non-default values reach the URL — a bare /customers should
            // stay bare rather than growing ten empty parameters.
            if (merged.segment) next.segment = merged.segment;
            if (merged.q) next.q = merged.q;
            if (merged.bot) next.bot = merged.bot;
            if (merged.country) next.country = merged.country;
            if (merged.tag) next.tag = merged.tag;
            if (merged.wallet) next.wallet = merged.wallet;
            if (merged.from) next.from = merged.from;
            if (merged.to) next.to = merged.to;
            if (merged.sort !== 'last_seen_at') next.sort = merged.sort;
            if (merged.dir !== 'desc') next.dir = merged.dir;
            if (merged.perPage !== 50) next.per_page = merged.perPage;

            // Any filter change invalidates the page number: the row that was
            // on page 7 is very unlikely to still be there.
            if (patch.page !== undefined && patch.page > 1) {
                next.page = patch.page;
            }

            router.get(route('customers.index'), next, {
                only: RELOAD_ONLY,
                preserveState: true,
                preserveScroll: true,
                replace: options.replace ?? false,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            });
        },
        [filters],
    );

    /** Same column flips direction; a new column starts descending. */
    const sortBy = useCallback(
        (key: SortKey) => {
            const dir: 'asc' | 'desc' =
                filters.sort === key && filters.dir === 'desc' ? 'asc' : 'desc';

            apply({ sort: key, dir, page: 1 });
        },
        [apply, filters.sort, filters.dir],
    );

    const reset = useCallback(() => {
        router.get(
            route('customers.index'),
            {},
            { only: RELOAD_ONLY, preserveState: true, preserveScroll: true },
        );
    }, []);

    return { apply, sortBy, reset, busy };
}

/**
 * The search box.
 *
 * Local state pushed to the URL on a delay — bound straight to the query
 * string it would fire a request per keystroke, and each of those is an ILIKE
 * across the tenant's whole customer table.
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

    // Adopt the server's value when the page is navigated to with a different
    // one (back button, a shared link), but not on every render.
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
