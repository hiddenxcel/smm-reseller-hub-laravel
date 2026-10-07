export type BotStatus = {
    number: string | null;
    numberStatus: string | null;
    connected: boolean;
    subscription: 'active' | 'sandbox' | 'inactive';
    live: boolean;
};

export type Setup = {
    /** The three things that must all hold before the bot can sell. */
    checks: { subscription: boolean; panel: boolean; whatsapp: boolean };
    sandbox: boolean;
    testNumbers: string[];
    lang: string;
    groupUrl: string;
    websiteUrl: string;
    supportMode: 'admin' | 'ai';
    /** Send the customer's money back when the provider cancels an order or delivers only part of it. */
    autoRefund: boolean;
    staff: string[];
    ai: {
        active: boolean;
        hasKey: boolean;
        answersToday: number;
        answersTotal: number;
    };
};

export type Language = { code: string; name: string };

export type Commands = {
    refill: boolean;
    status: boolean;
    cancel: boolean;
    speedup: boolean;
};

export type Spam = {
    enabled: boolean;
    repeat_threshold: number;
    window_minutes: number;
    disable_minutes: number;
};

export type Conversation = {
    phone: string;
    name: string | null;
    lastMessage: string | null;
    lastDirection: 'in' | 'out';
    lastAt: string | null;
    blocked: boolean;
};

export type ThreadMessage = {
    id: number;
    direction: 'in' | 'out';
    message: string | null;
    at: string | null;
};

export type InboxThread = {
    phone: string;
    name: string | null;
    blocked: boolean;
    balance: number | null;
    messages: ThreadMessage[];
};

export type Provider = {
    id: number;
    name: string;
    panelType: string;
    apiUrl: string;
    authMethod: string | null;
    status: string;
    balance: number | null;
    currency: string | null;
    /** What the reseller actually imported — the number that makes deleting costly. */
    importedServices: number;
    /** What detection last saw on the panel itself. */
    catalogueSize: number | null;
    lastCheckedAt: string | null;
};

export type GatewayField = {
    name: string;
    label: string;
    /** Something is stored here — the value itself never travels. */
    saved: boolean;
};

export type GatewayOption = {
    code: string;
    label: string;
    type: string | null;
    /** Has a client behind it; without this no payment can be taken. */
    ready: boolean;
    needsPhone: boolean;
    verify: boolean;
    /** Its notification id comes from an API call, not from a dashboard. */
    registersIpn: boolean;
    /** Where the provider must be told to send notifications, when it takes one URL set in its own dashboard. */
    webhookUrl: string | null;
    connected: boolean;
    status: string | null;
    /** The one customers are sent to. At most one per reseller. */
    isDefault: boolean;
    fields: GatewayField[];
};

export type FamilyState = {
    family: string;
    label: string;
    intro: string;
    keyLabel: string;
    secretLabel: string;
    secretRequired: boolean;
    /** Whether a secret key is stored — never the key itself. */
    keySaved: boolean;
    webhookSecretSaved: boolean;
    markets: Array<{ code: string; label: string; on: boolean; isDefault: boolean }>;
};

export type PanelLimit = {
    max: number;
    used: number;
    reached: boolean;
};

export type LogRow = {
    id: number;
    phone: string;
    direction: 'in' | 'out';
    message: string | null;
    at: string | null;
};

export type Logs = {
    q: string;
    rows: LogRow[];
    page: number;
    lastPage: number;
    total: number;
};

export type Settings = {
    staff: string[];
    testNumbers: string[];
    currency: string;
    lang: string;
    minTopup: number | string;
    referralPercent: number | string;
    showProviderName: boolean;
    detailedStatus: boolean;
};

/**
 * Only the current tab's data is sent, so every tab payload is optional —
 * the page checks before rendering rather than trusting it is there.
 */
export type CurrencyOption = {
    code: string;
    name: string;
    /** Units of this currency one US dollar buys. */
    perUsd: number;
};

export type OrderBotPageProps = {
    tab: string;
    tabs: string[];
    status: BotStatus;
    setup?: Setup;
    numbers?: import('./NumberTab').BotNumbersData;
    languages?: Language[];
    currencies?: CurrencyOption[];
    commands?: Commands;
    spam?: Spam;
    logs?: Logs;
    settings?: Settings;
};
