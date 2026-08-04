<?php

namespace App\Http\Controllers;

use App\Models\NumberRental;
use App\Models\PlatformNumber;
use App\Models\TenantPanel;
use App\Models\TenantPaymentGateway;
use App\Models\TenantWhatsApp;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingStep;
use App\Services\Panel\ServiceCatalogue;
use App\Services\Payments\Gateway;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a reseller changes what setup first put in place.
 *
 * The wizard walks someone from signing up to a working shop, once. After
 * that it is the wrong shape entirely: someone who only wants to rotate a
 * panel API key should not be walked through five ordered steps to reach the
 * field. This screen holds the same things, flat, each editable on its own.
 *
 * It deliberately reuses the wizard's controllers rather than restating their
 * writes — one place decides what a valid panel is, and it stays that way.
 * Those actions redirect `back()`, so the same form works from either screen.
 *
 * A tenant whose setup breaks after go-live (their only panel deleted, say)
 * is NOT sent back to the wizard. They stay here and the broken tab is
 * flagged, because someone already running a shop being dropped into an
 * onboarding flow reads as a fault, not as help.
 */
class SettingsController extends Controller
{
    private const TABS = ['panel', 'services', 'whatsapp', 'payments'];

    public function __construct(private ServiceCatalogue $catalogue) {}

    public function show(Request $request, string $tab = 'panel'): Response
    {
        $tenant = $request->user();
        $progress = OnboardingProgress::for($tenant);

        return Inertia::render('Settings/Index', [
            'tab' => $tab,
            'tabs' => self::TABS,

            // Which tabs need attention, so the nav can flag them without
            // every tab's payload being built to find out.
            'incomplete' => $this->incompleteTabs($progress),

            // Each tab's payload is built only when it is the one being
            // shown. Reading the catalogue calls the panel over the network,
            // which has no business happening because someone opened Payments.
            ...match ($tab) {
                'services' => $this->servicesPayload($tenant->id),
                'whatsapp' => $this->whatsappPayload($tenant->id),
                'payments' => $this->paymentsPayload($tenant->id),
                default => $this->panelPayload($tenant->id),
            },
        ]);
    }

    /**
     * Tabs whose underlying step is not satisfied.
     *
     * Keyed by tab so the front end can flag one without knowing the step
     * names. Payments is reported like the rest and marked optional, since a
     * reseller can run on manually credited wallets.
     *
     * @return array<string, bool>
     */
    private function incompleteTabs(OnboardingProgress $progress): array
    {
        $map = [
            'panel' => OnboardingStep::ConnectPanel,
            'services' => OnboardingStep::ImportServices,
            'whatsapp' => OnboardingStep::ConnectWhatsApp,
            'payments' => OnboardingStep::SetupPayments,
        ];

        return array_map(
            fn (OnboardingStep $step) => ! $progress->isComplete($step),
            $map,
        );
    }

    /**
     * Every panel, not just the active one the wizard cares about.
     *
     * Keys are never sent back to the browser — only whether one is stored,
     * so the form can say "leave blank to keep".
     */
    private function panelPayload(int $tenantId): array
    {
        return [
            'panels' => TenantPanel::where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get()
                ->map(fn (TenantPanel $panel) => [
                    'id' => $panel->id,
                    'name' => $panel->name,
                    'api_url' => $panel->api_url,
                    'panel_type' => $panel->panel_type,
                    'status' => $panel->status,
                    'balance' => $panel->last_balance,
                    'currency' => $panel->balance_currency,
                    'servicesCount' => $panel->services_count,
                    'lastCheckedAt' => $panel->last_checked_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * The catalogue for re-importing.
     *
     * Calling the panel can be slow or fail outright, so the page renders
     * either way and says what went wrong — the same contract the wizard's
     * import step has.
     */
    private function servicesPayload(int $tenantId): array
    {
        $panel = TenantPanel::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if ($panel === null) {
            return [
                'panel' => null,
                'services' => [],
                'catalogueError' => null,
                'importedCount' => 0,
            ];
        }

        $catalogue = $this->catalogue->forPanel($panel);

        return [
            'panel' => ['id' => $panel->id, 'name' => $panel->name],
            'services' => $catalogue->services,
            'catalogueError' => $catalogue->message,
            'importedCount' => \App\Models\BotService::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->count(),
        ];
    }

    private function whatsappPayload(int $tenantId): array
    {
        return [
            'webhookUrl' => route('webhooks.whatsapp'),
            'verifyToken' => (string) config('services.meta.verify_token'),
            'numbers' => TenantWhatsApp::where('tenant_id', $tenantId)
                ->get()
                ->map(fn (TenantWhatsApp $number) => [
                    'id' => $number->id,
                    'phone_number_id' => $number->phone_number_id,
                    'display_number' => $number->display_number,
                    'bot_type' => $number->bot_type,
                    'source' => $number->source,
                ])
                ->all(),
            'rentable' => PlatformNumber::where('status', 'available')
                ->orderBy('country')
                ->orderBy('display_number')
                ->get()
                ->map(fn (PlatformNumber $number) => [
                    'id' => $number->id,
                    'display_number' => $number->display_number,
                    'country' => $number->country,
                    'country_code' => $number->country_code,
                    'currency' => $number->currency,
                    'price' => (float) $number->monthly_cost,
                ])
                ->all(),
            'rentals' => NumberRental::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->with('platformNumber')
                ->get()
                ->map(fn (NumberRental $rental) => [
                    'id' => $rental->id,
                    'display_number' => $rental->platformNumber?->display_number,
                    'country' => $rental->platformNumber?->country,
                    'startedAt' => $rental->starts_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * Credentials are never sent back to the browser — only the fact that a
     * gateway is connected, so the form can say "leave blank to keep".
     */
    private function paymentsPayload(int $tenantId): array
    {
        return [
            'gateways' => array_values(array_map(fn (string $code) => [
                'code' => $code,
                'label' => Gateway::label($code),
                'type' => config("gateways.{$code}.type"),
                'ready' => Gateway::isReady($code),
                'fields' => array_map(fn (array $field) => [
                    'name' => $field['name'],
                    'label' => $field['label'],
                ], config("gateways.{$code}.fields", [])),
            ], array_keys(Gateway::all()))),

            'connected' => TenantPaymentGateway::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->get()
                ->map(fn (TenantPaymentGateway $row) => [
                    'code' => $row->gateway,
                    'label' => Gateway::label($row->gateway),
                    'ready' => Gateway::isReady($row->gateway),
                ])
                ->all(),
        ];
    }
}
