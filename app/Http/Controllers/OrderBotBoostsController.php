<?php

namespace App\Http\Controllers;

use App\Services\Billing\PlatformGateways;
use App\Services\Billing\Pricing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The order bot's shop for extra features.
 *
 * Nothing here has a checkout of its own: a boost is a subscription like any
 * other, bought through BillingController::checkout, so a payment, a webhook
 * and an expiry date behave exactly as they do for the bots. This page only
 * chooses what to put on the shelf (config billing.features).
 */
class OrderBotBoostsController extends Controller
{
    public function __invoke(Request $request, BillingController $billing): Response
    {
        $tenant = $request->user();

        return Inertia::render('OrderBot/Boosts', [
            'boosts' => $billing->services((int) $tenant->id, 'features'),
            'terms' => Pricing::terms(),
            'gateways' => PlatformGateways::available(),
            'currency' => (string) config('billing.currency', 'USD'),
            'credit' => (float) $tenant->referral_credit,
            'brandName' => $tenant->brandName(),
            'businessName' => (string) $tenant->business_name,
        ]);
    }
}
