/**
 * The currency this reseller's shop is priced in.
 *
 * The money formatters are plain functions called from dozens of places, so
 * the currency cannot reach them as a prop without threading it through every
 * caller. The layout sets it once per render, before any page content draws,
 * and the formatters read it as their default.
 *
 * Anything that is genuinely in another currency — a panel's balance, the
 * platform's own subscription price — still passes its own code explicitly.
 */
let current = 'USD';

export function setShopCurrency(code: string | null | undefined): void {
    current = code && /^[A-Z]{3}$/.test(code) ? code : 'USD';
}

export function shopCurrency(): string {
    return current;
}
