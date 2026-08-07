<?php

/*
|--------------------------------------------------------------------------
| What a reseller pays the platform
|--------------------------------------------------------------------------
|
| Distinct from config/gateways.php, which is what a reseller's OWN customers
| pay THEM through. This file is the other direction: SaaS billing.
|
| Prices themselves live in the `plans` table, one row per service, as a
| monthly figure. The terms below turn that into a total — the discount curve
| is a business rule rather than per-plan data, so it lives here and applies
| to every service at once.
|
| Making a term cheaper means editing one number. Adding a term means adding
| one row; nothing in the checkout has to change.
|
*/

return [

    /*
    | Terms a reseller can buy. `discount` is taken off (monthly × months),
    | so 0.10 on the 3-month term means "three months for the price of 2.7".
    */
    'terms' => [
        1 => ['label' => 'Monthly', 'discount' => 0.00],
        3 => ['label' => '3 months', 'discount' => 0.10],
        6 => ['label' => '6 months', 'discount' => 0.15],
        12 => ['label' => '12 months', 'discount' => 0.25],
    ],

    /*
    | Services a reseller can subscribe to, in the order they are offered.
    | A service missing a `plans` row is simply not on sale yet — the
    | checkout skips it rather than showing a price of zero.
    */
    'sellable' => [
        'order_bot',
        'support_bot',
    ],

    /*
    | The platform's own gateways — HiddenXcel's credentials, not a
    | reseller's. Only what is listed here can take a subscription payment,
    | and only if its keys are set in config/services.php under 'billing'.
    |
    | `charge_currency` is what the gateway is actually charged in, when that
    | differs from the price. Snippe is Tanzanian mobile money and settles in
    | TZS; the payment row stays in USD so accounting has one currency.
    |
    | Binance is deliberately absent: it has no webhook, so it needs the payer
    | to report an order ID and a screen to verify it on. It can be added back
    | once that flow is built.
    */
    'gateways' => [
        'snippe' => [
            'label' => 'Mobile Money',
            'type' => 'mobile',
            'charge_currency' => 'TZS',
        ],
        'cryptomus' => [
            'label' => 'Crypto (Cryptomus)',
            'type' => 'crypto',
        ],
        'heleket' => [
            'label' => 'Crypto (Heleket)',
            'type' => 'crypto',
        ],
        'nowpayments' => [
            'label' => 'Crypto (NOWPayments)',
            'type' => 'crypto',
        ],
    ],

    'currency' => 'USD',

    /*
    | Referral credit is spent as a discount, but never all the way to zero:
    | there has to be a real transaction for the gateway to confirm, or a
    | subscription would activate on a payment that never happened.
    */
    'minimum_charge' => 1.00,

];
