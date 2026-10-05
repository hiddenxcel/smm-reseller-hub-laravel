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

/**
 * Set only when the signed-in account is the public demo. Present or absent —
 * the banner is a truthy check, not an email comparison in the component.
 */
export interface Demo {
    readOnly: boolean;
    /** How often the account is rebuilt, so the banner can say so. */
    resetMinutes: number;
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
        /**
         * Set only when the person signed in is a team member rather than the
         * owner. `can` is the nav rows their role may open.
         */
        member: {
            name: string;
            role: 'admin' | 'support' | 'viewer';
            roleLabel: string;
            can: string[];
        } | null;
    };
    impersonation: Impersonation | null;
    /** Set only on the public demo account — see LockDemoAccount. */
    demo: Demo | null;
    announcements: Announcement[];
    /** Support tickets whose last word was ours — the sidebar badge. */
    supportUnread: number;
    flash: Flash;
    /**
     * Laravel's route list, shared for `route()`. In the browser it also
     * arrives as a global from Blade's @routes; under SSR this prop is the
     * only copy, since Node has no window for that tag to write to.
     */
    ziggy: {
        url: string;
        location: string;
        routes: Record<string, unknown>;
    };
};
