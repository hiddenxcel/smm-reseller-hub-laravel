<?php

namespace App\Http\Controllers;

use App\Models\Plan;
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

        return Inertia::render('Landing', [
            'plans' => $plans,
        ]);
    }
}
