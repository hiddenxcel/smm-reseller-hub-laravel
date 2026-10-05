<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Assistant\AssistantKey;
use App\Services\Payments\Gateway;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The marketing pages that are not the landing page.
 *
 * They share a controller because they share their data: every one of them
 * wants the plan list, the demo number, or both, and splitting that across
 * five controllers would mean five places to update when a plan is added.
 */
class PublicPageController extends Controller
{
    public function features(): Response
    {
        return Inertia::render('Public/Features', [
            'plans' => $this->plans(),
            ...$this->chrome(),
        ]);
    }

    public function services(): Response
    {
        return Inertia::render('Public/Services', [
            'plans' => $this->plans(),
            ...$this->chrome(),
        ]);
    }

    public function pricing(): Response
    {
        return Inertia::render('Public/Pricing', [
            // Every service here, unlike the landing page's three: someone on
            // the pricing page has come to compare, so hiding two of them
            // would be hiding the answer they came for.
            'plans' => $this->plans(),
            'gateways' => $this->gateways(),
            ...$this->chrome(),
        ]);
    }

    public function apiDocs(): Response
    {
        return Inertia::render('Public/ApiDocs', [
            'baseUrl' => rtrim(config('app.url'), '/').'/api/v2',
            ...$this->chrome(),
        ]);
    }

    /**
     * Where a gateway sends a customer back to after they pay.
     *
     * Static and public: it can say nothing about the payment (arriving here only
     * proves they left the gateway's page), so it points them back to the chat,
     * where the bot reports the real outcome.
     */
    public function paymentThanks(): Response
    {
        return Inertia::render('Public/PaymentThanks', [
            ...$this->chrome(),
        ]);
    }

    public function contact(): Response
    {
        return Inertia::render('Public/Contact', [
            ...$this->chrome(),
        ]);
    }

    /**
     * What every public page needs regardless of what it is about: the
     * floating assistant and the WhatsApp number behind its escalation.
     *
     * One method rather than a repeated pair of lines, so a third piece of
     * page furniture is added in one place instead of five.
     *
     * @return array<string, mixed>
     */
    private function chrome(): array
    {
        return [
            'demoNumber' => config('services.demo_whatsapp_number'),
            'assistantEnabled' => AssistantKey::widgetEnabled(),
        ];
    }

    /** @return array<string, array> */
    private function plans(): array
    {
        return Plan::where('status', 'active')
            ->orderBy('sort_order')
            ->get()
            ->keyBy(fn (Plan $plan) => $plan->service_key->value)
            ->map(fn (Plan $plan) => [
                'name' => $plan->name,
                'description' => $plan->description,
                'monthly' => (float) $plan->price_monthly,
                'yearly' => (float) $plan->price_yearly,
                'currency' => $plan->currency,
            ])
            ->all();
    }

    /** @return array<int, array> */
    private function gateways(): array
    {
        return collect(Gateway::all())
            ->filter(fn (array $gateway) => Arr::get($gateway, 'ready', false))
            ->map(fn (array $gateway, string $code) => [
                'code' => $code,
                'label' => Arr::get($gateway, 'label', $code),
                'type' => Arr::get($gateway, 'type', 'card'),
            ])
            ->values()
            ->all();
    }
}
