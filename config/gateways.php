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
|   fields — the credential inputs, and which of the three encrypted columns
|            (api_key_enc / webhook_secret_enc / extra_enc) each one lands in.
|
| Adding a gateway means writing a client that implements PaymentGateway, one
| line in GatewayFactory, and an entry here. The dashboard form, the bot's
| payment menu and the webhook route all come from this file, so none of them
| change. GatewayContractTest holds each new entry to that contract.
|
*/

return [

    // ---- Snippe: one account, three markets ----
    //
    // Tanzania is a direct USSD push to the customer's phone. Kenya and Uganda
    // go through Snippe's hosted checkout page, which collects the number
    // itself — so the bot does not ask for one. All three charge in TZS under
    // the hood and convert for the payer; see SnippeClient.

    'snippe' => [
        'label' => 'Snippe (Tanzania — TZS)',
        'type' => 'mobile',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

    'snippe_ke' => [
        'label' => 'Snippe (Kenya — KES)',
        'type' => 'card',
        'ready' => true,
        // A hosted checkout, not a push: the customer is sent a link.
        'fields' => [
            ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

    'snippe_ug' => [
        'label' => 'Snippe (Uganda — UGX)',
        'type' => 'card',
        'ready' => true,
        // A hosted checkout, not a push: the customer is sent a link.
        'fields' => [
            ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

    // ---- FimiPay: one gateway per market ----
    //
    // Each market is its own gateway because what differs between them — the
    // currency, the country code a phone number is completed with, and how the
    // customer pays on FimiPay's page — is decided by which one they pick, not
    // guessed. All five run on the one FimipayClient. FimiPay takes a phone for
    // every market; where the bot does not ask for one (cards) it uses the
    // number the customer is already chatting from.

    'fimipay_ng' => [
        'label' => 'FimiPay (Nigeria — NGN)',
        'type' => 'mobile',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

    'fimipay_gh' => [
        'label' => 'FimiPay (Ghana — GHS)',
        'type' => 'mobile',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

    'fimipay_cm' => [
        'label' => 'FimiPay (Cameroon — XAF)',
        'type' => 'mobile',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

    'fimipay_za' => [
        'label' => 'FimiPay (South Africa — ZAR)',
        'type' => 'card',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret'],
        ],
    ],

    'fimipay_usd' => [
        'label' => 'FimiPay (International card — USD)',
        'type' => 'card',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
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
        'label' => 'Binance Pay (USDT / Crypto)',
        'type' => 'crypto',
        'ready' => true,
        // Binance Pay is a merchant API with a real webhook, so this is an
        // ordinary signed-notification gateway. It was once configured as
        // `verify` — the customer quoting an order id from an internal
        // transfer — but no code was ever written for that, and the client
        // that does exist is the merchant one.
        //
        // The credentials come from Binance Merchant → Developers, not from
        // the Spot API keys used for trading; those cannot open an order.
        'fields' => [
            ['name' => 'api_key', 'label' => 'Merchant API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Merchant API secret', 'store' => 'webhook_secret'],
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

    'flutterwave' => [
        'label' => 'Flutterwave (Cards / Mobile)',
        'type' => 'card',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Secret hash', 'store' => 'webhook_secret'],
        ],
    ],

    'stripe' => [
        'label' => 'Stripe (Cards)',
        'type' => 'card',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook signing secret', 'store' => 'webhook_secret'],
        ],
    ],

    'paypal' => [
        'label' => 'PayPal',
        'type' => 'card',
        'ready' => true,
        // Three values, not two: PayPal verifies a webhook by posting it back
        // along with the id of the webhook subscription that sent it, which is
        // neither of the credentials used to call the API.
        'fields' => [
            ['name' => 'api_key', 'label' => 'Client ID', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Secret', 'store' => 'webhook_secret'],
            ['name' => 'extra', 'label' => 'Webhook ID', 'store' => 'extra'],
        ],
    ],

    'paystack' => [
        'label' => 'Paystack (West Africa)',
        'type' => 'card',
        'ready' => true,
        // Paystack signs webhooks with the secret key itself and issues no
        // separate webhook secret, so the reseller enters the one key and it
        // is stored in both slots.
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Secret key (again, for webhooks)', 'store' => 'webhook_secret'],
        ],
    ],

    'razorpay' => [
        'label' => 'Razorpay (India)',
        'type' => 'card',
        'ready' => true,
        // Three values: the key pair for the API, plus the webhook secret the
        // reseller chooses when they add the webhook in Razorpay's dashboard.
        'fields' => [
            ['name' => 'api_key', 'label' => 'Key ID', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Key secret', 'store' => 'webhook_secret'],
            ['name' => 'extra', 'label' => 'Webhook secret', 'store' => 'extra'],
        ],
    ],

    'pesapal' => [
        'label' => 'Pesapal (East Africa)',
        'type' => 'card',
        'ready' => true,
        // Its IPN arrives, but carries no payment status — Pesapal withholds
        // it deliberately. Distinct from `verify`, which means there is no
        // webhook at all and the payer quotes a reference themselves.
        'confirm_by_api' => true,
        // The IPN id is issued once, when the notification URL is registered,
        // and every order has to quote it.
        'fields' => [
            ['name' => 'api_key', 'label' => 'Consumer key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Consumer secret', 'store' => 'webhook_secret'],
            ['name' => 'extra', 'label' => 'IPN ID', 'store' => 'extra'],
        ],
    ],

    // ---- selectable now, wired later ----

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
