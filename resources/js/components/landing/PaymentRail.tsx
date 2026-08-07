import { CreditCard, Smartphone, Wallet } from 'lucide-react';

type Gateway = {
    code: string;
    label: string;
    type: 'mobile' | 'crypto' | 'card' | string;
};

/**
 * The gateways a reseller can switch on, shown as name plates rather than
 * official logos.
 *
 * Deliberately not the real marks. M-Pesa, Stripe and PayPal are other
 * companies' trademarks, and a wall of them next to our own branding reads as
 * a claim of partnership none of them have given us. Setting the name in our
 * own type says the same true thing — you can take money this way — without
 * borrowing anyone's identity.
 *
 * The list comes from config/gateways.php via the controller, so adding a
 * gateway there makes it appear here on its own.
 */

/** What the customer ends up paying with, where the brand name hides it. */
const PLAIN_NAME: Record<string, string> = {
    snippe: 'M-Pesa · Tigo · Airtel',
    zenopay: 'M-Pesa · Tigo · Airtel',
    momopay: 'Mobile money',
    nowpayments: 'USDT · BTC · Crypto',
    binance: 'Binance USDT',
    cryptomus: 'USDT · Crypto',
    heleket: 'USDT · Crypto',
    flutterwave: 'Cards · Mobile money',
    stripe: 'Cards',
    paypal: 'PayPal',
    pesapal: 'Cards · Mobile money',
};

const ICON: Record<string, typeof Wallet> = {
    mobile: Smartphone,
    crypto: Wallet,
    card: CreditCard,
};

/** The brand name on its own, without the parenthetical the dashboard needs. */
function shortName(label: string): string {
    return label.replace(/\s*\(.*\)\s*$/, '').trim();
}

export default function PaymentRail({ gateways }: { gateways: Gateway[] }) {
    if (gateways.length === 0) {
        return null;
    }

    return (
        <div>
            <p className="text-center text-sm text-muted-foreground">
                Your customers pay you through your own accounts
            </p>

            <ul className="mt-5 flex flex-wrap items-stretch justify-center gap-2.5">
                {gateways.map((gateway) => {
                    const Icon = ICON[gateway.type] ?? CreditCard;
                    const plain = PLAIN_NAME[gateway.code];

                    return (
                        <li
                            key={gateway.code}
                            className="flex items-center gap-2.5 rounded-xl border border-border bg-card px-3.5 py-2.5"
                        >
                            <Icon className="size-4 shrink-0 text-primary" aria-hidden />

                            <span className="min-w-0">
                                <span className="block text-sm leading-tight font-semibold">
                                    {shortName(gateway.label)}
                                </span>
                                {plain && (
                                    <span className="block text-xs leading-tight text-muted-foreground">
                                        {plain}
                                    </span>
                                )}
                            </span>
                        </li>
                    );
                })}
            </ul>

            <p className="mt-5 text-center text-xs text-muted-foreground">
                The money goes to you, not through us. Names shown are the
                gateways you connect — we are not affiliated with them.
            </p>
        </div>
    );
}
