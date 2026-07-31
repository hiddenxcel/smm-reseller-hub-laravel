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
    | reseller's. Only gateways listed here can take a subscription payment.
    | Each must have its keys set in config/services.php.
    */
    'gateways' => [
        'cryptomus',
        'heleket',
        'nowpayments',
    ],

    'currency' => 'USD',

];
