<?php

/*
|--------------------------------------------------------------------------
| Exchange rates
|--------------------------------------------------------------------------
|
| How many units of each currency one US dollar buys. A reseller prices their
| shop in one currency — usually USD — and a gateway collects in another: Snippe
| settles in TZS, FimiPay in NGN, GHS, XAF or ZAR. This is the bridge, so a
| customer is charged the right local amount while their wallet is credited in
| the shop's own currency.
|
| These are working figures, not a market feed: a shop's prices do not change
| with the market every hour, and a rate that moves under a customer between
| the quote and the payment is worse than one that is slightly stale. Set the
| current ones in .env, one line each, when they have drifted:
|
|     CURRENCY_USD_TO_TZS=2600
|
| A currency that is not listed cannot be converted to or from, and a gateway
| that needs it refuses the payment with that reason instead of guessing.
|
*/

return [

    'usd_to' => [
        'USD' => 1.0,
        // TZS honours the older Snippe-specific setting so an existing .env
        // keeps working unchanged.
        'TZS' => (float) env('CURRENCY_USD_TO_TZS', env('BILLING_SNIPPE_USD_TO_TZS', 2600)),
        'KES' => (float) env('CURRENCY_USD_TO_KES', 129),
        'UGX' => (float) env('CURRENCY_USD_TO_UGX', 3700),
        'NGN' => (float) env('CURRENCY_USD_TO_NGN', 1550),
        'GHS' => (float) env('CURRENCY_USD_TO_GHS', 15),
        'XAF' => (float) env('CURRENCY_USD_TO_XAF', 605),
        'ZAR' => (float) env('CURRENCY_USD_TO_ZAR', 18),
        'EUR' => (float) env('CURRENCY_USD_TO_EUR', 0.92),
        'GBP' => (float) env('CURRENCY_USD_TO_GBP', 0.79),
    ],

    /*
    | What each currency is called, for the picker a reseller chooses their
    | shop's currency from. A currency needs an entry in BOTH lists: a rate
    | without a name would show as a bare code, and a name without a rate would
    | offer something no gateway can convert.
    */
    'names' => [
        'USD' => 'US dollar',
        'TZS' => 'Tanzanian shilling',
        'KES' => 'Kenyan shilling',
        'UGX' => 'Ugandan shilling',
        'NGN' => 'Nigerian naira',
        'GHS' => 'Ghanaian cedi',
        'XAF' => 'Central African franc',
        'ZAR' => 'South African rand',
        'EUR' => 'Euro',
        'GBP' => 'British pound',
    ],

];
