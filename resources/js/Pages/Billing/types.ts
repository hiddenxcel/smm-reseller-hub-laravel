export type Term = {
    months: number;
    label: string;
    /** 0.10 means "three months for the price of 2.7". */
    discount: number;
};

export type ServiceState = 'active' | 'sandbox' | 'locked';

export type SellableService = {
    key: string;
    name: string;
    description: string | null;
    /** Cents. Everything on this screen is integer cents. */
    monthly: number;
    state: ServiceState;
    endsAt: string | null;
    /** Negative once lapsed — "expired 3 days ago" is not "expires today". */
    daysLeft: number | null;
    /** months (as a string key) => total in cents. */
    termPrices: Record<string, number>;
};

export type Gateway = {
    code: string;
    label: string;
    type: string;
    needsPhone?: boolean;
};

export type RentableNumber = {
    id: number;
    displayNumber: string;
    /** Cents, bought outright — never discounted by the term. */
    cost: number;
};

export type Invoice = {
    id: number;
    reference: string;
    gateway: string;
    amount: number;
    creditApplied: number;
    currency: string;
    months: number | null;
    status: string;
    items: string[];
    at: string | null;
};

export type BillingPageProps = {
    services: SellableService[];
    terms: Term[];
    gateways: Gateway[];
    currency: string;
    /** Referral credit, in whole currency units rather than cents. */
    credit: number;
    numbers: RentableNumber[];
    invoices: Invoice[];
};
