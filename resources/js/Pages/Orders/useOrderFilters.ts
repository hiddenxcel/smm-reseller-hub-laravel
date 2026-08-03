import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Filters, SortKey } from './types';

/**
 * Filter state, held in the URL rather than in React.
 *
 * Everything the table shows is derived from the query string, which is what
 * makes a filtered view shareable and refresh-proof. Each change is a partial
 * Inertia visit: only the props the table needs come back, so filtering a
 * 40,000-row list does not re-render the sidebar or re-run the dashboard.
 */
const RELOAD_ONLY = ['orders', 'filters', 'isFiltered', 'tabCounts', 'summary'];

type Patch = Partial<Filters> & { page?: number };

export function useOrderFilters(filters: Filters) {
    const [busy, setBusy] = useState(false);

    const apply = useCallback(
        (patch: Patch, options: { replace?: boolean } = {}) => {
            const next: Record<string, string | number> = {};

            const merged = { ...filters, ...patch };

            // Only non-default values reach the URL — a bare /orders should
            // stay bare rather than growing eight empty parameters.
            if (merged.status) next.status = merged.status;
            if (merged.payment) next.payment = merged.payment;
            if (merged.q) next.q = merged.q;
            if (merged.panel) next.panel = merged.panel;
            if (merged.from) next.from = merged.from;
            if (merged.to) next.to = merged.to;
            if (merged.sort !== 'created_at') next.sort = merged.sort;
            if (merged.dir !== 'desc') next.dir = merged.dir;
            if (merged.perPage !== 50) next.per_page = merged.perPage;

            // Any change to the filter invalidates the page number: the row
            // that was on page 7 is very unlikely to still be there.
            if (patch.page !== undefined) {
                if (patch.page > 1) next.page = patch.page;
            }

            router.get(route('orders.index'), next, {
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

    /** Clicking a column header: same column flips direction, new column starts descending. */
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
            route('orders.index'),
            {},
            { only: RELOAD_ONLY, preserveState: true, preserveScroll: true },
        );
    }, []);

    return { apply, sortBy, reset, busy };
}

/**
 * The search box.
 *
 * Kept as local state and pushed to the URL on a delay — binding it straight
 * to the query string would fire a request per keystroke, and each of those is
 * a LIKE across the tenant's whole orders table.
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
