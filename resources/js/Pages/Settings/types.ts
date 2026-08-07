export type PanelRow = {
    id: number;
    name: string;
    api_url: string;
    panel_type: string | null;
    status: string;
    balance: number | string | null;
    currency: string | null;
    servicesCount: number | null;
    lastCheckedAt: string | null;
};

export type CatalogueService = {
    provider_service_id: string;
    name: string;
    platform: string;
    category: string | null;
    cost_price: number | string | null;
    min_quantity: number;
    max_quantity: number;
};

export type WhatsAppNumber = {
    id: number;
    phone_number_id: string;
    display_number: string | null;
    bot_type: string;
    source: string;
};

export type RentableNumber = {
    id: number;
    display_number: string | null;
    country: string | null;
    country_code: string | null;
    currency: string | null;
    price: number;
};

export type Rental = {
    id: number;
    display_number: string | null;
    country: string | null;
    startedAt: string | null;
};

export type GatewayField = { name: string; label: string };

export type GatewayOption = {
    code: string;
    label: string;
    type: string | null;
    /** Has a client behind it; without this no payment can be taken. */
    ready: boolean;
    fields: GatewayField[];
};

export type ConnectedGateway = {
    code: string;
    label: string;
    ready: boolean;
};

/**
 * Only the current tab's data is sent, so every tab payload is optional —
 * the page checks before rendering rather than trusting it is there.
 */
export type SettingsPageProps = {
    tab: string;
    tabs: string[];
    /** Which tabs still need attention, keyed by tab name. */
    incomplete: Record<string, boolean>;

    panels?: PanelRow[];

    panel?: { id: number; name: string } | null;
    services?: CatalogueService[];
    catalogueError?: string | null;
    importedCount?: number;

    webhookUrl?: string;
    verifyToken?: string;
    numbers?: WhatsAppNumber[];
    rentable?: RentableNumber[];
    rentals?: Rental[];

    gateways?: GatewayOption[];
    connected?: ConnectedGateway[];
};
