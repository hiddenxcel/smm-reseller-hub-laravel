/** Mirrors CustomerSegment.php. Derived from behaviour, never stored. */
export type Segment = 'blocked' | 'vip' | 'new' | 'returning' | 'active';

export type BotType = 'order' | 'support';

export type CustomerRow = {
    id: number;
    name: string | null;
    phone: string;
    email: string | null;
    country: string | null;
    lang: string;
    tags: string[];
    orders: number;
    spent: number;
    balance: number;
    referralCode: string | null;
    bots: BotType[];
    segments: Segment[];
    blocked: boolean;
    lastSeenAt: string | null;
    createdAt: string | null;
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
    | 'last_seen_at'
    | 'created_at'
    | 'name'
    | 'phone'
    | 'balance'
    | 'total_spent'
    | 'orders';

export type Filters = {
    segment: Segment | null;
    q: string | null;
    bot: 'order' | 'support' | 'both' | null;
    country: string | null;
    tag: string | null;
    wallet: string | null;
    from: string | null;
    to: string | null;
    sort: SortKey;
    dir: 'asc' | 'desc';
    perPage: number;
};

export type Kpi = { value: number; delta: number | null };

export type CustomersPageProps = {
    customers: { data: CustomerRow[]; meta: PageMeta };
    filters: Filters;
    isFiltered: boolean;
    /** Deferred — undefined on first paint, then filled in. */
    kpis?: {
        total: Kpi;
        newToday: Kpi;
        vip: Kpi;
        active: Kpi;
        wallets: Kpi;
        lifetime: Kpi;
    };
    tabCounts?: Record<'all' | Segment, number>;
    options?: { countries: string[]; tags: string[] };
    pageSizes: number[];
    walletBands: string[];
    bulkLimits: { default: number; broadcast: number };
    hasWhatsApp: boolean;
};

// ---- Slide-over tabs, each fetched on demand ----------------------------

export type TabKey =
    | 'overview'
    | 'orders'
    | 'messages'
    | 'wallet'
    | 'tickets'
    | 'activity';

export type Overview = {
    id: number;
    name: string | null;
    phone: string;
    email: string | null;
    country: string | null;
    lang: string;
    notes: string | null;
    tags: string[];
    blocked: boolean;
    blockedAt: string | null;
    joinedAt: string | null;
    lastSeenAt: string | null;
    orders: { total: number; completed: number; cancelled: number };
    spent: number;
    balance: number;
    preferredPayment: string | null;
    lastPaymentPhone: string | null;
    referral: {
        code: string | null;
        earnings: number;
        invited: number;
        invitedBy: { id: number; name: string | null; phone: string } | null;
    };
    segments: Segment[];
};

export type ProfileOrder = {
    id: number;
    service: string | null;
    quantity: number | null;
    amount: number | null;
    charge: number | null;
    profit: number | null;
    status: 'completed' | 'processing' | 'pending' | 'failed';
    rawStatus: string | null;
    at: string | null;
};

export type ProfileMessage = {
    id: number;
    direction: 'in' | 'out';
    text: string | null;
    bot: BotType | null;
    templateKey: string | null;
    at: string | null;
};

export type ProfileWallet = {
    balance: number;
    spent: number;
    referralEarnings: number;
    transactions: Array<{
        id: number;
        type: string;
        gateway: string;
        reference: string | null;
        amount: number;
        status: string;
        at: string | null;
    }>;
};

export type ProfileTicket = {
    id: number;
    subject: string | null;
    status: string;
    priority: string;
    category: string;
    subcategory: string | null;
    orderRef: string | null;
    at: string | null;
};

export type ProfileActivity = {
    type: string;
    at: string;
    title: string;
    detail: string | null;
    amount?: number | null;
    status?: string;
};

/** What the tab endpoint returns, one key per tab. */
export type TabPayload = {
    overview?: Overview;
    orders?: ProfileOrder[];
    messages?: ProfileMessage[];
    canReply?: boolean;
    windowClosesAt?: string | null;
    wallet?: ProfileWallet;
    tickets?: ProfileTicket[];
    activity?: ProfileActivity[];
};
