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

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: Tenant;
    };
};
