/** Mirrors BotService::STATUSES. */
export type ServiceStatus = 'active' | 'hidden' | 'paused';

export type ServiceRow = {
    id: number;
    name: string;
    description: string | null;
    /** What the bot tells a customer about this service before they order. */
    quality: string | null;
    speed: string | null;
    /** Free text, in the reseller's own words. */
    dropInfo: string | null;
    refillInfo: string | null;
    linkInstructions: string | null;
    platform: string;
    category: string | null;
    providerServiceId: string | null;
    panel: string | null;
    panelId: number | null;
    /** Null when the panel never reported one — not zero. */
    cost: number | null;
    price: number;
    profit: number | null;
    margin: number | null;
    underwater: boolean;
    minQuantity: number;
    maxQuantity: number;
    unitLabel: string;
    status: ServiceStatus;
    featured: boolean;
    requiresApproval: boolean;
    autoPaused: boolean;
    orders: number;
    lastSyncedAt: string | null;
    updatedAt: string | null;
};

export type PageMeta = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type SortKey =
    | 'name'
    | 'platform'
    | 'cost_price'
    | 'my_price'
    | 'profit'
    | 'margin'
    | 'updated_at';

export type MarginBand = 'loss' | 'thin' | 'healthy' | 'high' | 'unknown';

export type Filters = {
    status: ServiceStatus | null;
    platform: string | null;
    category: string | null;
    panel: number | null;
    q: string | null;
    min_price: number | null;
    max_price: number | null;
    margin: MarginBand | null;
    featured: boolean;
    sort: SortKey;
    dir: 'asc' | 'desc';
    perPage: number;
};

export type Kpis = {
    total: number;
    active: number;
    hidden: number;
    paused: number;
    platforms: number;
    avgProfit: number | null;
    avgMargin: number | null;
    underwater: number;
    lastSyncedAt: string | null;
};

export type PricingRule = {
    id: number;
    name: string;
    platform: string | null;
    panelId: number | null;
    mode: 'percent' | 'fixed' | 'multiplier';
    amount: number;
    minProfit: number | null;
    maxProfit: number | null;
    roundTo: number | null;
    active: boolean;
    sortOrder: number;
    describe: string;
};

export type ServicesPageProps = {
    services: { data: ServiceRow[]; meta: PageMeta };
    filters: Filters;
    isFiltered: boolean;
    /** Deferred — undefined on first paint. */
    kpis?: Kpis;
    tabCounts?: Record<'all' | ServiceStatus, number>;
    platforms?: Array<{ platform: string; services: number; active: number }>;
    categories?: Array<{ category: string; services: number }>;
    panels: Array<{ id: number; name: string }>;
    rules: PricingRule[];
    pageSizes: number[];
    marginBands: MarginBand[];
    bulkLimits: { default: number; pricing: number };
};

// ---- Drawer tabs ---------------------------------------------------------

export type TabKey = 'overview' | 'pricing' | 'orders' | 'logs' | 'settings';

export type Overview = {
    id: number;
    name: string;
    description: string | null;
    platform: string;
    category: string | null;
    providerServiceId: string | null;
    panel: string | null;
    panelId: number | null;
    unitLabel: string;
    minQuantity: number;
    maxQuantity: number;
    linkInstructions: string | null;
    status: ServiceStatus;
    autoPaused: boolean;
    featured: boolean;
    requiresApproval: boolean;
    lastSyncedAt: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    performance: {
        orders: number;
        completed: number;
        revenue: number;
        /** Real profit taken, not the theoretical margin. Null with no spend. */
        profit: number | null;
    };
};

export type Pricing = {
    cost: number | null;
    price: number;
    profit: number | null;
    margin: number | null;
    underwater: boolean;
    syncedCost: number | null;
    history: PriceLog[];
};

export type PriceLog = {
    id: number;
    reason: 'manual' | 'bulk' | 'rule' | 'sync' | 'import';
    oldPrice: number | null;
    newPrice: number;
    oldCost: number | null;
    newCost: number | null;
    note: string | null;
    at: string | null;
};

export type ServiceOrder = {
    id: number;
    customer: string;
    quantity: number | null;
    charge: number | null;
    cost: number | null;
    profit: number | null;
    status: 'completed' | 'processing' | 'pending' | 'failed';
    rawStatus: string | null;
    at: string | null;
};

export type Settings = {
    status: ServiceStatus;
    featured: boolean;
    requiresApproval: boolean;
    sortOrder: number;
    linkInstructions: string | null;
    unitLabel: string;
    minQuantity: number;
    maxQuantity: number;
};

export type TabPayload = {
    overview?: Overview;
    pricing?: Pricing;
    orders?: ServiceOrder[];
    logs?: PriceLog[];
    settings?: Settings;
};

// ---- Bulk pricing --------------------------------------------------------

export type BulkMode = 'percent' | 'fixed' | 'set' | 'markup';

export type PricingPreviewRow = {
    id: number;
    name: string;
    from: string;
    to: string;
    cost: string | null;
    profit: string | null;
    underwater: boolean;
};

// ---- Import wizard -------------------------------------------------------

export type CatalogueEntry = {
    provider_service_id: string;
    name: string;
    platform: string;
    category: string | null;
    cost_price: string;
    suggested_price: string;
    min_quantity: number;
    max_quantity: number;
    imported: boolean;
};
