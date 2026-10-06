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

/*
| Beyond the currencies this was first built for, a long list of others a
| customer might want to see prices in — Africa first, then the Gulf, Asia,
| Europe, the Americas and Oceania. Each is [units per US dollar, name].
|
| The pegged ones (the Gulf dirhams and riyals, the CFA francs, the Hong Kong
| dollar) are exact; the rest are rough working figures that will have drifted
| by the time you read this. They are only a starting point: set the current
| rate in .env for any that matter,
|
|     CURRENCY_USD_TO_ETB=135
|
| and remember a customer sees these as approximate (≈) — what they pay is
| always in the shop's own currency.
*/
$more = [
    // Africa
    'RWF' => [1400, 'Rwandan franc'],
    'BIF' => [2950, 'Burundian franc'],
    'CDF' => [2800, 'Congolese franc'],
    'ETB' => [130, 'Ethiopian birr'],
    'SOS' => [571, 'Somali shilling'],
    'ZMW' => [27, 'Zambian kwacha'],
    'MWK' => [1730, 'Malawian kwacha'],
    'MZN' => [64, 'Mozambican metical'],
    'BWP' => [13.5, 'Botswana pula'],
    'NAD' => [18, 'Namibian dollar'],
    'AOA' => [920, 'Angolan kwanza'],
    'XOF' => [605, 'West African CFA franc'],
    'GMD' => [70, 'Gambian dalasi'],
    'SLE' => [23, 'Sierra Leonean leone'],
    'LRD' => [190, 'Liberian dollar'],
    'MUR' => [45, 'Mauritian rupee'],
    'EGP' => [50, 'Egyptian pound'],
    'MAD' => [9.7, 'Moroccan dirham'],
    'DZD' => [134, 'Algerian dinar'],
    'TND' => [3.1, 'Tunisian dinar'],
    'LYD' => [5.4, 'Libyan dinar'],

    // The Gulf and the Middle East
    'AED' => [3.6725, 'UAE dirham'],
    'SAR' => [3.75, 'Saudi riyal'],
    'QAR' => [3.64, 'Qatari riyal'],
    'KWD' => [0.307, 'Kuwaiti dinar'],
    'BHD' => [0.376, 'Bahraini dinar'],
    'OMR' => [0.3845, 'Omani rial'],
    'JOD' => [0.709, 'Jordanian dinar'],
    'ILS' => [3.7, 'Israeli shekel'],
    'TRY' => [40, 'Turkish lira'],

    // Asia
    'PKR' => [280, 'Pakistani rupee'],
    'BDT' => [120, 'Bangladeshi taka'],
    'LKR' => [300, 'Sri Lankan rupee'],
    'NPR' => [140, 'Nepalese rupee'],
    'CNY' => [7.2, 'Chinese yuan'],
    'HKD' => [7.8, 'Hong Kong dollar'],
    'JPY' => [150, 'Japanese yen'],
    'KRW' => [1400, 'South Korean won'],
    'SGD' => [1.34, 'Singapore dollar'],
    'MYR' => [4.4, 'Malaysian ringgit'],
    'IDR' => [16300, 'Indonesian rupiah'],
    'PHP' => [57, 'Philippine peso'],
    'THB' => [35, 'Thai baht'],
    'VND' => [25000, 'Vietnamese dong'],
    'KZT' => [520, 'Kazakhstani tenge'],

    // Europe
    'CHF' => [0.88, 'Swiss franc'],
    'SEK' => [10.5, 'Swedish krona'],
    'NOK' => [10.7, 'Norwegian krone'],
    'DKK' => [6.9, 'Danish krone'],
    'PLN' => [4.0, 'Polish zloty'],
    'CZK' => [23, 'Czech koruna'],
    'HUF' => [360, 'Hungarian forint'],
    'RON' => [4.6, 'Romanian leu'],
    'RUB' => [90, 'Russian ruble'],
    'UAH' => [41, 'Ukrainian hryvnia'],
    'GEL' => [2.7, 'Georgian lari'],
    'AZN' => [1.7, 'Azerbaijani manat'],

    // The Americas and Oceania
    'CAD' => [1.37, 'Canadian dollar'],
    'MXN' => [19.5, 'Mexican peso'],
    'BRL' => [5.5, 'Brazilian real'],
    'COP' => [4200, 'Colombian peso'],
    'CLP' => [950, 'Chilean peso'],
    'PEN' => [3.7, 'Peruvian sol'],
    'AUD' => [1.52, 'Australian dollar'],
    'NZD' => [1.67, 'New Zealand dollar'],
];

$extraRates = [];
$extraNames = [];

foreach ($more as $code => [$rate, $name]) {
    $extraRates[$code] = (float) env("CURRENCY_USD_TO_{$code}", $rate);
    $extraNames[$code] = $name;
}

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
        'INR' => (float) env('CURRENCY_USD_TO_INR', 88),

        ...$extraRates,
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
        'INR' => 'Indian rupee',

        ...$extraNames,
    ],

];
