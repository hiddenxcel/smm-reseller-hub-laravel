/** The four groups every panel status folds into. Mirrors OrderStatus.php. */
export type OrderStatusGroup = 'completed' | 'processing' | 'pending' | 'failed';

export type OrderAction = 'retry' | 'refill' | 'cancel' | 'mark';

export type OrderRow = {
    id: number;
    providerOrderId: string | null;
    customer: string;
    customerId: number | null;
    service: string | null;
    serviceId: string | null;
    link: string | null;
    quantity: number | null;
    amount: number | null;
    charge: number | null;
    paymentStatus: string;
    paidFrom: string | null;
    status: OrderStatusGroup;
    rawStatus: string | null;
    refillStatus: string | null;
    error: string | null;
    panel: string | null;
    panelId: number | null;
    createdAt: string | null;
    updatedAt: string | null;
    actions: OrderAction[];
};

export type PageMeta = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type Filters = {
    status: OrderStatusGroup | null;
    payment: string | null;
    q: string | null;
    panel: number | null;
    from: string | null;
    to: string | null;
    sort: SortKey;
    dir: 'asc' | 'desc';
    perPage: number;
};

export type SortKey =
    | 'created_at'
    | 'amount'
    | 'quantity'
    | 'service'
    | 'customer'
    | 'status';

export type OrdersPageProps = {
    orders: { data: OrderRow[]; meta: PageMeta };
    filters: Filters;
    isFiltered: boolean;
    /** Deferred — undefined on the first paint, then filled in. */
    tabCounts?: Record<'all' | OrderStatusGroup, number>;
    summary?: { orders: number; revenue: number };
    panels: Array<{ id: number; name: string }>;
    pageSizes: number[];
    statusLabels: Record<OrderStatusGroup, string>;
    bulkLimits: { default: number; panel: number };
};
