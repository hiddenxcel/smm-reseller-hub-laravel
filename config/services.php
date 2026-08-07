<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API
    |--------------------------------------------------------------------------
    |
    | Platform-level rather than per-reseller: Meta signs every webhook with
    | the app secret regardless of whose number the message arrived on.
    | Without it, inbound webhooks are rejected — anyone who found the URL
    | could otherwise drive any reseller's bot.
    |
    */

    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | The platform's own merchant accounts
    |--------------------------------------------------------------------------
    |
    | HiddenXcel's credentials, used when a RESELLER pays the platform for a
    | subscription. Not to be confused with the keys a reseller stores in
    | tenant_payment_gateways, which are their own accounts for taking money
    | from their customers — two different directions of money.
    |
    | A gateway listed in config/billing.php but missing its keys here is
    | skipped at checkout rather than offered and then failing.
    |
    */

    'billing' => [
        'nowpayments' => [
            'api_key' => env('BILLING_NOWPAYMENTS_API_KEY'),
            'ipn_secret' => env('BILLING_NOWPAYMENTS_IPN_SECRET'),
            'pay_currency' => env('BILLING_NOWPAYMENTS_PAY_CURRENCY', 'usdttrc20'),
        ],

        'cryptomus' => [
            'api_key' => env('BILLING_CRYPTOMUS_API_KEY'),
            'merchant_id' => env('BILLING_CRYPTOMUS_MERCHANT_ID'),
        ],

        'heleket' => [
            'api_key' => env('BILLING_HELEKET_API_KEY'),
            'merchant_id' => env('BILLING_HELEKET_MERCHANT_ID'),
        ],

        'snippe' => [
            'api_key' => env('BILLING_SNIPPE_API_KEY'),
            'webhook_secret' => env('BILLING_SNIPPE_WEBHOOK_SECRET'),
            // Snippe is Tanzanian mobile money and charges in TZS, but plans
            // are priced in USD — so the amount is converted for this gateway
            // alone. The payment row stays in USD for accounting.
            'usd_to_tzs' => (float) env('BILLING_SNIPPE_USD_TO_TZS', 2600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo number
    |--------------------------------------------------------------------------
    |
    | A WhatsApp number a visitor can message to meet the bot before signing
    | up. The landing page's floating button hides itself when this is unset,
    | because a chat nobody answers reads as a broken product rather than a
    | missing demo.
    |
    | Digits only, with country code: 255700000000
    |
    */

    'demo_whatsapp_number' => env('DEMO_WHATSAPP_NUMBER'),

];
