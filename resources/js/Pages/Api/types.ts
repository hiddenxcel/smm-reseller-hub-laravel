export type ApiKeyRow = {
    id: number;
    prefix: string;
    label: string | null;
    status: 'active' | 'revoked';
    rateLimit: number | null;
    defaultRateLimit: number;
    ipAllowlist: string[];
    lastUsedAt: string | null;
    lastUsedIp: string | null;
    createdAt: string | null;
    customer: { id: number; name: string | null; phone: string } | null;
};

export type CustomerOption = {
    id: number;
    name: string | null;
    phone: string;
    balance: string;
};

export type DocAction = {
    action: string;
    summary: string;
    params: { name: string; note: string }[];
    example: string;
};

export type LogRow = {
    id: number;
    action: string | null;
    ok: boolean;
    error: string | null;
    ip: string | null;
    details: Record<string, unknown> | null;
    durationMs: number | null;
    createdAt: string | null;
    key: { id: number; prefix: string; label: string | null } | null;
};

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};

export type ApiPageProps = {
    tab: string;
    tabs: string[];
    endpoint: string;

    /** Only the tab being viewed is built, so the rest are absent. */
    keys?: ApiKeyRow[];
    customers?: CustomerOption[];
    actions?: DocAction[];
    logs?: Paginated<LogRow>;
    filters?: { key: string; failed: boolean };
};
