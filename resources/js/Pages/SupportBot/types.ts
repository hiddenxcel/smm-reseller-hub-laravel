import { BotStatus, Language } from '../OrderBot/types';

export type { BotStatus, Language };

export type TicketCounts = {
    open: number;
    pending: number;
    resolved: number;
    closed: number;
};

export type MenuOption = {
    value: string;
    label: string;
    /** The settings key that switches it off, or null if it is always on. */
    toggle: string | null;
    enabled: boolean;
};

export type Overview = {
    checks: {
        subscription: boolean;
        panel: boolean;
        whatsapp: boolean;
        rules: boolean;
    };
    sandbox: boolean;
    testNumbers: string[];
    tickets: TicketCounts;
    /** Customers who asked for a person and have not been handed back. */
    awaitingHuman: number;
    menu: MenuOption[];
};

export type Rule = {
    id: number;
    panelId: number | null;
    panelName: string | null;
    type: 'guarantee' | 'no_guarantee';
    keyword: string;
    /** 0 means lifetime; null on a no_guarantee rule. */
    refillDays: number | null;
    status: 'active' | 'inactive';
    /** An earlier rule has the same keyword, so this one is never used. */
    shadowed: boolean;
};

export type RefillPolicy = {
    /** Read "30 Days Refill" / "No Refill" from the service name. */
    autoRead: boolean;
    /** For a service that says nothing: refuse, allow, or ask a person. */
    default: 'refuse' | 'allow' | 'human';
    /** For an order the bot did not place, so its service is unknown. */
    unknownOrder: 'refuse' | 'allow' | 'human';
};

export type PanelOption = { id: number; name: string };

export type TemplateRow = {
    key: string;
    description: string;
    /** The reseller's own wording, or '' when the built-in default is in use. */
    content: string;
    custom: boolean;
};

export type Templates = {
    lang: string;
    rows: TemplateRow[];
};

export type SupportSettings = {
    commands: {
        refill: boolean;
        status: boolean;
        cancel: boolean;
        speedup: boolean;
    };
    spam: {
        enabled: boolean;
        repeat_threshold: number;
        window_minutes: number;
        disable_minutes: number;
    };
    staff: string[];
    testNumbers: string[];
    lang: string;
    verification: Verification;
    staffAlerts: import('../OrderBot/types').StaffAlertsData;
};

export type Verification = {
    panelName: string | null;
    adminApiUrl: string | null;
    /** The key is never sent back; only that one is stored. */
    hasKey: boolean;
    required: boolean;
    linkedCount: number;
};

/** Only the current tab's data is sent, so every tab payload is optional. */
export type SupportBotPageProps = {
    tab: string;
    tabs: string[];
    status: BotStatus;
    overview?: Overview;
    numbers?: import('../OrderBot/NumberTab').BotNumbersData;
    rules?: Rule[];
    panels?: PanelOption[];
    refillPolicy?: RefillPolicy;
    templates?: Templates;
    languages?: Language[];
    settings?: SupportSettings;
};

export type SupportConversation = {
    phone: string;
    name: string | null;
    lastMessage: string | null;
    lastDirection: 'in' | 'out';
    lastAt: string | null;
    blocked: boolean;
    /** A person owns this conversation; the bot is silent on it. */
    awaitingHuman: boolean;
};

export type SupportMessage = {
    /** Prefixed 'm'/'t' — the thread merges two tables, so ids can collide. */
    id: string;
    sender: 'customer' | 'bot' | 'staff';
    message: string | null;
    at: string | null;
};

export type SupportThread = {
    phone: string;
    name: string | null;
    blocked: boolean;
    balance: number | null;
    ticketId: number | null;
    handedOver: boolean;
    /** Meta refuses free-form replies more than 24h after the last inbound. */
    withinWindow: boolean;
    messages: SupportMessage[];
};

export type TicketRow = {
    id: number;
    customer: string | null;
    category: 'ai' | 'human';
    subcategory: string | null;
    orderRef: string | null;
    subject: string | null;
    status: 'open' | 'pending' | 'resolved' | 'closed';
    priority: 'low' | 'normal' | 'high';
    handedOver: boolean;
    messages: number;
    updatedAt: string | null;
    createdAt: string | null;
};

export type TicketThreadMessage = {
    id: number;
    sender: 'customer' | 'ai' | 'staff';
    message: string;
    at: string | null;
};

/**
 * The detail view carries the thread itself where the list carried a count,
 * so `messages` is replaced rather than widened.
 */
export type TicketDetail = Omit<TicketRow, 'messages'> & {
    withinWindow: boolean;
    canSend: boolean;
    messages: TicketThreadMessage[];
};
