<?php

/*
|--------------------------------------------------------------------------
| Payment gateways a reseller can offer their own customers
|--------------------------------------------------------------------------
|
| Distinct from the platform's own billing gateways in config/services.php:
| these are the ones a reseller plugs their credentials into so their
| customers can top up a wallet.
|
| Each entry declares:
|   label  — shown in the dashboard
|   type   — mobile | crypto | card. 'mobile' is what makes the bot ask for
|            a phone number to push the payment prompt to.
|   ready  — whether a client exists and the top-up flow can actually drive
|            it. Gateways marked false are still selectable so a reseller
|            can store keys ahead of time, but show as "coming soon".
|   verify — no webhook: the payer reports a reference we check ourselves.
|   fields — the credential inputs, and which of the two encrypted columns
|            (api_key_enc / webhook_secret_enc) each one lands in.
|
| Making a gateway live later means adding a client, a case in the top-up
| flow, and flipping ready to true. Nothing in the dashboard changes.
|
*/

return [

    'snippe' => [
        'label' => 'Snippe (Mobile Money)',
        'type' => 'mobile',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

    'nowpayments' => [
        'label' => 'NOWPayments (USDT / Crypto)',
        'type' => 'crypto',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'IPN secret', 'store' => 'webhook_secret'],
        ],
    ],

    'binance' => [
        'label' => 'Binance (USDT — Internal Transfer)',
        'type' => 'crypto',
        'ready' => true,
        // No webhook: the customer sends USDT to the reseller's Binance ID and
        // reports the order ID, which is verified against the Spot API.
        'verify' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Binance Spot API key (read)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Binance Spot API secret', 'store' => 'webhook_secret'],
        ],
    ],

    'cryptomus' => [
        'label' => 'Cryptomus (USDT / Crypto)',
        'type' => 'crypto',
        'ready' => true,
        // Needs a merchant UUID as well as a key, so it borrows the second
        // secret slot rather than adding a column.
        'fields' => [
            ['name' => 'api_key', 'label' => 'Payment API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Merchant UUID', 'store' => 'webhook_secret'],
        ],
    ],

    'heleket' => [
        'label' => 'Heleket (USDT / Crypto)',
        'type' => 'crypto',
        'ready' => true,
        // Shares Cryptomus's API, so the same two slots mean the same things.
        'fields' => [
            ['name' => 'api_key', 'label' => 'Payment API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Merchant UUID', 'store' => 'webhook_secret'],
        ],
    ],

    // ---- selectable now, wired later ----

    'flutterwave' => [
        'label' => 'Flutterwave (Cards / Mobile)',
        'type' => 'card',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook hash', 'store' => 'webhook_secret'],
        ],
    ],

    'stripe' => [
        'label' => 'Stripe (Cards)',
        'type' => 'card',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook signing secret', 'store' => 'webhook_secret'],
        ],
    ],

    'paypal' => [
        'label' => 'PayPal',
        'type' => 'card',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Client ID', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Secret', 'store' => 'webhook_secret'],
        ],
    ],

    'pesapal' => [
        'label' => 'Pesapal (East Africa)',
        'type' => 'card',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Consumer key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Consumer secret', 'store' => 'webhook_secret'],
        ],
    ],

    'zenopay' => [
        'label' => 'ZenoPay (Mobile Money)',
        'type' => 'mobile',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

    'momopay' => [
        'label' => 'MoMoPay (Mobile Money)',
        'type' => 'mobile',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

];
