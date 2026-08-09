/** Shapes the admin console's pages receive from the server. */

export type ServiceState = 'active' | 'sandbox' | 'expired' | 'locked';

export type PlatformKpis = {
    revenue: { value: number; previous: number; delta: number | null };
    signups: { value: number; previous: number; delta: number | null };
    tenants: {
        total: number;
        active: number;
        suspended: number;
        paying: number;
    };
    subscriptions: {
        active: number;
        sandbox: number;
        expiringSoon: number;
    };
    ordersToday: number;
};

export type PlatformAlerts = {
    failedPayments: number;
    openTickets: number;
    expiringSoon: number;
    suspendedTenants: number;
    silentNumbers: number;
};

export type TrendPoint = {
    date: string;
    revenue: number;
    signups: number;
};

export type TopReseller = {
    tenantId: number;
    name: string;
    payments: number;
    revenue: number;
};

export type RecentSignup = {
    id: number;
    name: string;
    email: string;
    status: string;
    paid: boolean;
    at: string | null;
};

export type TenantRow = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: string;
    lang: string;
    credit: number;
    hasPaid: boolean;
    services: Record<string, ServiceState>;
    revenue: number;
    orders: number;
    joinedAt: string | null;
};

export type TenantFilters = {
    status: string | null;
    q: string | null;
    service: string | null;
    billing: string | null;
    from: string | null;
    to: string | null;
    sort: string;
    dir: string;
    perPage: number;
};

export type PageMeta = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
};

/** What the signed-in admin's role permits, so the screen offers only that. */
export type Abilities = {
    edit: boolean;
    suspend: boolean;
    impersonate: boolean;
    credit: boolean;
};

export type TenantOverview = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: string;
    lang: string;
    referralCode: string | null;
    referralCredit: number;
    hasPaid: boolean;
    referredBy: { id: number; name: string } | null;
    joinedAt: string | null;
    stats: {
        revenue: number;
        orders: number;
        customers: number;
        walletsHeld: number;
        ordersLast30: number;
    };
    services: Record<string, ServiceState>;
};

export type TenantSubscription = {
    id: number;
    service: string;
    plan: string | null;
    status: string;
    startsAt: string | null;
    endsAt: string | null;
    autoRenew: boolean;
    expired: boolean;
};

export type TenantPayment = {
    id: number;
    gateway: string;
    reference: string | null;
    amount: number;
    creditApplied: number;
    currency: string | null;
    months: number | null;
    items: unknown;
    status: string;
    at: string | null;
};

export type TenantOrder = {
    id: number;
    service: string | null;
    customer: string | null;
    quantity: number | null;
    amount: number | null;
    status: string | null;
    paymentStatus: string | null;
    at: string | null;
};

export type TenantCustomer = {
    id: number;
    phone: string;
    name: string | null;
    balance: number;
    totalSpent: number;
    blocked: boolean;
    lastSeenAt: string | null;
};

export type TenantPanelRow = {
    id: number;
    name: string | null;
    type: string | null;
    url: string | null;
    balance: number | null;
    currency: string | null;
    services: number | null;
    checkedAt: string | null;
    status: string | null;
};

export type TenantGatewayRow = {
    id: number;
    gateway: string;
    status: string | null;
    isDefault: boolean;
};

export type TenantNumberRow = {
    id: number;
    display: string | null;
    source: string | null;
    bot: string | null;
    status: string | null;
};

export type TenantRentalRow = {
    id: number;
    number: string | null;
    country: string | null;
    cost: number | null;
    currency: string | null;
    startsAt: string | null;
    endsAt: string | null;
    status: string | null;
};

// ---- plans ---------------------------------------------------------------

export type PlanLimits = {
    panels: number;
    numbers: number;
    orders: number;
    messages: number;
    refills: number;
};

export type PlanTerm = {
    months: number;
    label: string;
    total: number;
};

export type PlanRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    service: string;
    monthly: number;
    yearly: number;
    currency: string;
    limits: PlanLimits;
    status: string;
    sortOrder: number;
    liveSubscriptions: number;
    /** Priced here, but only offered at checkout if config lists it as sellable. */
    sellable: boolean;
    terms: PlanTerm[];
};

// ---- subscriptions -------------------------------------------------------

/** What a row actually is, which the raw status column alone does not say. */
export type SubscriptionState =
    | 'active'
    | 'trial'
    | 'expiring'
    | 'expired'
    | 'cancelled'
    | 'pending';

export type SubscriptionRow = {
    id: number;
    tenantId: number;
    tenant: string;
    service: string;
    plan: string | null;
    status: string;
    state: SubscriptionState;
    startsAt: string | null;
    endsAt: string | null;
    autoRenew: boolean;
    /** Negative once lapsed; null for an open-ended subscription. */
    daysLeft: number | null;
};

export type SubscriptionFilterState = {
    state: string | null;
    service: string | null;
    q: string | null;
    tenant: number | null;
    sort: string;
    dir: string;
    perPage: number;
};

// ---- payments ------------------------------------------------------------

export type PaymentRow = {
    id: number;
    tenantId: number;
    tenant: string;
    gateway: string;
    reference: string | null;
    amount: number;
    creditApplied: number;
    currency: string | null;
    months: number | null;
    status: string;
    items: unknown;
    at: string | null;
    /** Taken but never confirmed — the state a human has to settle. */
    stale: boolean;
};

export type PaymentDetail = PaymentRow & {
    rawResponse: string | null;
    binanceOrderId: string | null;
    planId: number | null;
    subscriptionId: number | null;
};

export type PaymentFilterState = {
    status: string | null;
    gateway: string | null;
    q: string | null;
    tenant: number | null;
    from: string | null;
    to: string | null;
    sort: string;
    dir: string;
    perPage: number;
};

export type PaymentTotals = {
    collected: number;
    creditApplied: number;
    pendingValue: number;
};

// ---- bots ----------------------------------------------------------------

export type BotKpis = {
    subscribed: number;
    sandbox: number;
    numbersConnected: number;
    messagesToday: number;
    messages7d: number;
    silent: number;
};

export type BotTrendPoint = { date: string; messages: number };

export type SilentBot = {
    tenantId: number;
    tenant: string;
    number: string | null;
    status: string | null;
    lastReplyAt: string | null;
};

export type TicketStats = {
    open: number;
    pending: number;
    resolved: number;
    closed: number;
    handedOver: number;
};

export type BotDefaults = {
    commands: Record<string, boolean>;
    spam: Record<string, number | boolean>;
    response: Record<string, boolean>;
    shop: Record<string, string | number>;
};

export type TemplateRow = {
    key: string;
    bot: string;
    about: string;
    lang: string;
    /** The platform default, if one has been written. */
    content: string | null;
    /** What the bot falls back to with no row at all — the translation file. */
    builtIn: string;
    isSet: boolean;
    overriddenBy: number;
};

// ---- catalogue -----------------------------------------------------------

export type CatalogueKpis = {
    services: number;
    active: number;
    autoPaused: number;
    panels: number;
    sellingTenants: number;
};

export type PlatformRow = { platform: string; services: number; tenants: number };

export type PanelRow = {
    url: string;
    host: string;
    tenants: number;
    unhealthy: number;
};

export type AutoPausedRow = {
    id: number;
    tenantId: number;
    tenant: string;
    name: string | null;
    platform: string | null;
    providerServiceId: string | null;
    syncedAt: string | null;
};

export type TopSellingRow = { name: string; orders: number; revenue: number };

export type CatalogueSizeRow = {
    tenantId: number;
    tenant: string;
    services: number;
};

// ---- reports -------------------------------------------------------------

export type MonthlyRow = {
    month: string;
    label: string;
    revenue: number;
    signups: number;
    payingResellers: number;
};

export type Mrr = {
    total: number;
    byService: Record<string, { subscriptions: number; mrr: number }>;
    /** Live subscriptions with no plan attached — a data gap, not a zero. */
    unpriced: number;
};

export type GrowthMetric = {
    value: number;
    previous: number;
    delta: number | null;
};

export type ConversionRow = {
    month: string;
    label: string;
    signups: number;
    paid: number;
    rate: number | null;
};

export type GatewayRow = { gateway: string; payments: number; revenue: number };

// ---- announcements -------------------------------------------------------

export type AnnouncementState = 'draft' | 'scheduled' | 'live' | 'expired';

export type AnnouncementRow = {
    id: number;
    title: string;
    body: string;
    level: 'info' | 'warning' | 'critical';
    dismissible: boolean;
    state: AnnouncementState;
    publishedAt: string | null;
    expiresAt: string | null;
    author: string | null;
    createdAt: string | null;
};

// ---- blog ----------------------------------------------------------------

export type BlogPostState = 'draft' | 'scheduled' | 'published';

export type BlogPostRow = {
    id: number;
    slug: string;
    title: string;
    excerpt: string;
    body: string;
    author: string | null;
    state: BlogPostState;
    publishedAt: string | null;
    createdAt: string | null;
};

// ---- tickets -------------------------------------------------------------

export type TicketRow = {
    id: number;
    tenantId: number;
    tenant: string;
    customer: string | null;
    subject: string | null;
    category: string | null;
    status: string;
    priority: string | null;
    handedOver: boolean;
    handedOverAt: string | null;
    lastCustomerAt: string | null;
    /** Handed to a person over a day ago and still open. */
    stalled: boolean;
    at: string | null;
};

export type TicketMessageRow = {
    id: number;
    sender: string | null;
    message: string | null;
    at: string | null;
};

export type TicketFilterState = {
    state: string | null;
    category: string | null;
    q: string | null;
    tenant: number | null;
    perPage: number;
};

// ---- system --------------------------------------------------------------

export type AdminUserRow = {
    id: number;
    username: string;
    name: string | null;
    email: string | null;
    role: string;
    status: string;
    lastLoginAt: string | null;
    lastLoginIp: string | null;
    isSelf: boolean;
};

export type PlatformSettings = {
    company_name: string;
    support_email: string;
    support_whatsapp: string;
    website_url: string;
    terms_url: string;
    privacy_url: string;
    referral_percent: number;
    registration_open: boolean;
};

export type GatewayField = {
    name: string;
    label: string;
    store: string;
    required?: boolean;
};

export type GatewayStatus = {
    code: string;
    label: string;
    type: string;
    /** Whether usable keys exist — never the keys themselves. */
    configured: boolean;
    /**
     * Where those keys came from. `env` cannot be edited from the console:
     * an environment value always wins, so a form there would let an owner
     * save something with no effect.
     */
    source: 'env' | 'database' | 'stored-disabled' | 'none';
    /** Last four characters of the key, enough to tell two apart. */
    hint: string | null;
    fields: GatewayField[];
    help: string | null;
};

export type SecurityKpis = {
    admins: number;
    activeAdmins: number;
    blockedIps: number;
    impersonations7d: number;
    openImpersonations: number;
    adminActions7d: number;
};

export type BlockedIpRow = {
    id: number;
    ip: string;
    reason: string | null;
    by: string | null;
    expiresAt: string | null;
    expired: boolean;
    at: string | null;
};

export type AdminAccessRow = {
    id: number;
    username: string;
    role: string;
    status: string;
    lastLoginAt: string | null;
    lastLoginIp: string | null;
    dormant: boolean;
};

export type AdminLoginRow = {
    id: number;
    admin: string | null;
    ip: string | null;
    at: string | null;
};

export type ImpersonationRow = {
    id: number;
    admin: string;
    tenantId: number;
    tenant: string;
    reason: string | null;
    ip: string | null;
    startedAt: string | null;
    endedAt: string | null;
    open: boolean;
};

export type AuditEntry = {
    id: number;
    actorType: string;
    actorId: number | null;
    actor: string | null;
    action: string;
    details: Record<string, unknown> | null;
    tenantId: number | null;
    ip: string | null;
    at: string | null;
};

export type AuditFilterState = {
    actor: string | null;
    action: string | null;
    q: string | null;
    tenant: number | null;
    from: string | null;
    to: string | null;
    perPage: number;
};

export type BackupRow = {
    file: string;
    bytes: number;
    at: string;
};

export type ActivityEntry = {
    id: number;
    actorType: string;
    actor: string | null;
    action: string;
    details: Record<string, unknown> | null;
    ip: string | null;
    at: string | null;
};
