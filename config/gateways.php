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
    //
    // A payment is confirmed by asking FimiPay for the order's status rather than
    // by verifying a signature, so the webhook secret is optional.

    'fimipay_ng' => [
        'label' => 'FimiPay (Nigeria — NGN)',
        'type' => 'mobile',
        'ready' => true,
        // Confirmed by asking FimiPay, not by trusting the notification: the
        // webhook only prompts a lookup of the order's status, and the answer
        // comes from FimiPay's API under our own key. That is why no webhook
        // secret is needed — see PaymentWebhookController.
        'confirm_by_api' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret (optional)', 'store' => 'webhook_secret', 'optional' => true],
        ],
    ],

    'fimipay_gh' => [
        'label' => 'FimiPay (Ghana — GHS)',
        'type' => 'mobile',
        'ready' => true,
        // Confirmed by asking FimiPay, not by trusting the notification: the
        // webhook only prompts a lookup of the order's status, and the answer
        // comes from FimiPay's API under our own key. That is why no webhook
        // secret is needed — see PaymentWebhookController.
        'confirm_by_api' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret (optional)', 'store' => 'webhook_secret', 'optional' => true],
        ],
    ],

    'fimipay_cm' => [
        'label' => 'FimiPay (Cameroon — XAF)',
        'type' => 'mobile',
        'ready' => true,
        // Confirmed by asking FimiPay, not by trusting the notification: the
        // webhook only prompts a lookup of the order's status, and the answer
        // comes from FimiPay's API under our own key. That is why no webhook
        // secret is needed — see PaymentWebhookController.
        'confirm_by_api' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret (optional)', 'store' => 'webhook_secret', 'optional' => true],
        ],
    ],

    'fimipay_za' => [
        'label' => 'FimiPay (South Africa — ZAR)',
        'type' => 'card',
        'ready' => true,
        // Confirmed by asking FimiPay, not by trusting the notification: the
        // webhook only prompts a lookup of the order's status, and the answer
        // comes from FimiPay's API under our own key. That is why no webhook
        // secret is needed — see PaymentWebhookController.
        'confirm_by_api' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret (optional)', 'store' => 'webhook_secret', 'optional' => true],
        ],
    ],

    'fimipay_usd' => [
        'label' => 'FimiPay (International card — USD)',
        'type' => 'card',
        'ready' => true,
        // Confirmed by asking FimiPay, not by trusting the notification: the
        // webhook only prompts a lookup of the order's status, and the answer
        // comes from FimiPay's API under our own key. That is why no webhook
        // secret is needed — see PaymentWebhookController.
        'confirm_by_api' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Secret key (sk_live_… or sk_test_…)', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret (optional)', 'store' => 'webhook_secret', 'optional' => true],
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

    // ---- AnyPay (anypay.io): cards and crypto on a hosted page ----
    //
    // The customer is sent a signed link to AnyPay's payment form. AnyPay
    // reports the result to ONE notification URL per project, which the
    // reseller pastes into their AnyPay project — it is shown on the form.
    // The secret key signs both the link and the notification, so it is what
    // proves a notification is genuine.

    'anypay' => [
        'label' => 'AnyPay (cards & crypto)',
        'type' => 'card',
        'ready' => true,
        // The reseller must paste our notification URL into their AnyPay
        // project; the gateways page shows it.
        'webhook_setup' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Project ID', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Secret key', 'store' => 'webhook_secret'],
        ],
    ],
    // ---- PayU (India): UPI, cards, net banking, wallets, charged in INR ----
    //
    // PayU takes the order as a POSTed form, so the customer's link goes to a
    // page of ours that submits it (see PaymentFormController). The result
    // comes back signed with the merchant salt, which is what confirms it.
    // The optional third value "test" switches to PayU's sandbox.

    'payu' => [
        'label' => 'PayU (UPI, India)',
        'type' => 'card',
        'ready' => true,
        'webhook_setup' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Merchant key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Merchant salt', 'store' => 'webhook_secret'],
            ['name' => 'extra', 'label' => 'Mode (type test for the sandbox, leave blank for live)', 'store' => 'extra', 'optional' => true],
        ],
    ],
    // ---- Paytm (India): UPI, cards, net banking, wallets, charged in INR ----
    //
    // Two steps: our server initiates the transaction with Paytm, then the
    // customer's browser posts the returned token to Paytm's payment page —
    // through a page of ours, since a chat can only carry a link. The result
    // comes back signed with the merchant key, which is what confirms it.
    // "test" in the third slot switches to Paytm's sandbox.

    'paytm' => [
        'label' => 'Paytm (UPI, India)',
        'type' => 'card',
        'ready' => true,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Merchant ID', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Merchant key', 'store' => 'webhook_secret'],
            ['name' => 'extra', 'label' => 'Mode (type test for the sandbox, leave blank for live)', 'store' => 'extra', 'optional' => true],
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

    // UPI and other Indian methods through providers that need an approved
    // merchant account. Keys can be saved ahead of time; none takes a payment
    // until it is built and tried against a real account.

    'bharatpe' => [
        'label' => 'BharatPe (UPI, India)',
        'type' => 'card',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Merchant ID', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Secret key', 'store' => 'webhook_secret', 'optional' => true],
        ],
    ],
    'ebanx' => [
        'label' => 'EBANX (UPI, India)',
        'type' => 'card',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'Integration key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret', 'optional' => true],
        ],
    ],

    'adyen' => [
        'label' => 'Adyen (UPI, India)',
        'type' => 'card',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'HMAC key', 'store' => 'webhook_secret', 'optional' => true],
            ['name' => 'extra', 'label' => 'Merchant account', 'store' => 'extra'],
        ],
    ],

    'ppro' => [
        'label' => 'PPRO (UPI, India)',
        'type' => 'card',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'API token', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret', 'optional' => true],
        ],
    ],

    'nomupay' => [
        'label' => 'Nomu Pay (UPI, India)',
        'type' => 'card',
        'ready' => false,
        'fields' => [
            ['name' => 'api_key', 'label' => 'API key', 'store' => 'api_key'],
            ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'store' => 'webhook_secret', 'optional' => true],
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
