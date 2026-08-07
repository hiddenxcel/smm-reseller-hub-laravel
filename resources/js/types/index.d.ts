/**
 * The authenticated user is a Tenant — a reseller — not Breeze's generic
 * User. There is no `name` column: businesses have a business_name.
 */
export interface Tenant {
    id: number;
    business_name: string;
    email: string;
    phone?: string | null;
    status: string;
    lang: string;
}

/**
 * The platform owner, behind /hx-control. A different session from a Tenant —
 * both can be present at once while an admin is viewing a reseller's account.
 */
export interface Admin {
    id: number;
    username: string;
    name: string;
    role: 'owner' | 'admin' | 'support';
}

/**
 * Set only while an admin is inside a reseller's account. Its presence is what
 * every screen checks — a truthy test rather than comparing two sessions.
 */
export interface Impersonation {
    tenant: string | null;
    readOnly: boolean;
}

/** A platform notice shown to every reseller on their dashboard. */
export interface Announcement {
    id: number;
    title: string;
    body: string;
    level: 'info' | 'warning' | 'critical';
    dismissible: boolean;
}

/** One-shot messages from the action just performed. */
export interface Flash {
    success?: string | null;
    error?: string | null;
    /**
     * A newly issued API key, in plaintext, for the single render after it was
     * created. Nothing can show it a second time — only a hash is stored.
     */
    newApiKey?: string | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: Tenant;
        admin: Admin | null;
    };
    impersonation: Impersonation | null;
    announcements: Announcement[];
    /** Support tickets whose last word was ours — the sidebar badge. */
    supportUnread: number;
    flash: Flash;
};
