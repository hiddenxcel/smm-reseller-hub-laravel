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
    /*
    | `fields` describes what the settings screen asks for and where to find
    | it. It lives here rather than in the component so adding a gateway is
    | one edit: the form, the validation and the instructions all read from
    | this. `store` names the column, because each provider calls its second
    | secret something different.
    */
    'gateways' => [
        'snippe' => [
            'label' => 'Mobile Money',
            'type' => 'mobile',
            'charge_currency' => 'TZS',
            'help' => 'Snippe settles M-Pesa, Tigo Pesa and Airtel Money in TZS. Find both values in your Snippe dashboard under API.',
            'fields' => [
                ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key', 'required' => true],
                ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret', 'required' => true],
            ],
        ],
        'cryptomus' => [
            'label' => 'Crypto (Cryptomus)',
            'type' => 'crypto',
            'help' => 'From your Cryptomus merchant settings. The merchant UUID is on the same page as the payment API key.',
            'fields' => [
                ['name' => 'api_key', 'label' => 'Payment API key', 'store' => 'api_key', 'required' => true],
                ['name' => 'extra', 'label' => 'Merchant UUID', 'store' => 'extra', 'required' => true],
            ],
        ],
        'heleket' => [
            'label' => 'Crypto (Heleket)',
            'type' => 'crypto',
            'help' => 'Heleket uses the same API as Cryptomus, so the two values mean the same things.',
            'fields' => [
                ['name' => 'api_key', 'label' => 'Payment API key', 'store' => 'api_key', 'required' => true],
                ['name' => 'extra', 'label' => 'Merchant UUID', 'store' => 'extra', 'required' => true],
            ],
        ],
        'nowpayments' => [
            'label' => 'Crypto (NOWPayments)',
            'type' => 'crypto',
            'help' => 'The IPN secret is separate from the API key — generate it in NOWPayments under Settings → IPN, or payment confirmations will be rejected.',
            'fields' => [
                ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key', 'required' => true],
                ['name' => 'webhook_secret', 'label' => 'IPN secret', 'store' => 'webhook_secret', 'required' => true],
            ],
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
