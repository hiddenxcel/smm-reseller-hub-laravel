<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Payments\Gateway;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    public function __invoke(): Response
    {
        // Prices come from the plans table so the page can never drift from
        // what checkout actually charges.
        $plans = Plan::where('status', 'active')
            ->orderBy('sort_order')
            ->get()
            ->keyBy(fn (Plan $plan) => $plan->service_key->value)
            ->map(fn (Plan $plan) => [
                'name' => $plan->name,
                'description' => $plan->description,
                'monthly' => (float) $plan->price_monthly,
                'yearly' => (float) $plan->price_yearly,
                'currency' => $plan->currency,
            ]);

        // Only what a reseller can switch on today. Reading config rather than
        // a hand-kept list means a gateway added later shows up here without
        // anyone remembering to edit the page — and one that is still `ready
        // => false` never gets advertised before it works.
        $gateways = collect(Gateway::all())
            ->filter(fn (array $gateway) => Arr::get($gateway, 'ready', false))
            ->map(fn (array $gateway, string $code) => [
                'code' => $code,
                'label' => Arr::get($gateway, 'label', $code),
                'type' => Arr::get($gateway, 'type', 'card'),
            ])
            ->values();

        return Inertia::render('Landing', [
            'plans' => $plans,
            'gateways' => $gateways,
            // Null until DEMO_WHATSAPP_NUMBER is set, and the button hides
            // itself — an unanswered chat reads as a broken product.
            'demoNumber' => config('services.demo_whatsapp_number'),
        ]);
    }
}
