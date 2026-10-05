<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\NumberRental;
use App\Models\PlatformNumber;
use App\Models\TenantPanel;
use App\Models\TenantPaymentGateway;
use App\Models\TenantWhatsApp;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingStep;
use App\Services\Onboarding\TestBotStatus;
use App\Services\Bots\BotSettings;
use App\Services\Simulator\BotSimulator;
use Illuminate\Support\Arr;
use App\Services\Panel\ServiceCatalogue;
use App\Services\Payments\Gateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The setup wizard a reseller lands in after signing up.
 *
 * Dropping someone into an empty dashboard is how they leave without ever
 * connecting anything; this walks them to a working shop instead.
 */
class OnboardingController extends Controller
{
    public function __construct(private ServiceCatalogue $catalogue) {}

    /** Send the reseller to whichever step they still need. */
    public function index(Request $request): RedirectResponse
    {
        $progress = OnboardingProgress::for($request->user());
        $step = $progress->currentStep();

        if ($step === null) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('onboarding.step', $step->value);
    }

    public function show(Request $request, string $step): Response|RedirectResponse
    {
        $current = OnboardingStep::tryFrom($step);

        if ($current === null) {
            return redirect()->route('onboarding');
        }

        $progress = OnboardingProgress::for($request->user());

        $shared = [
            'step' => $current->value,
            'steps' => $progress->toArray(),
            'completed' => $progress->completedCount(),
            'readyToGoLive' => $progress->isReadyToGoLive(),
            // Whether this step can be put off, so the page does not have to
            // restate the rule. Test is the exception: putting it off is what
            // staying in sandbox means, and it has its own wording for that.
            'canSkip' => ! $progress->isComplete($current),
        ];

        return match ($current) {
            OnboardingStep::TryBot => Inertia::render('Onboarding/TryBot', [
                ...$shared,
                'simulator' => $this->simulatorProps($request),
            ]),

            OnboardingStep::ConnectPanel => Inertia::render('Onboarding/ConnectPanel', $shared),

            OnboardingStep::ImportServices => $this->importServices($request, $shared),

            OnboardingStep::ConnectWhatsApp => Inertia::render('Onboarding/ConnectWhatsApp', [
                ...$shared,
                // The reseller pastes these two into their Meta app so Meta
                // knows where to deliver messages.
                'webhookUrl' => route('webhooks.whatsapp'),
                'verifyToken' => (string) config('services.meta.verify_token'),
                'numbers' => TenantWhatsApp::where('tenant_id', $request->user()->id)
                    ->get()
                    ->map(fn (TenantWhatsApp $number) => [
                        'id' => $number->id,
                        'phone_number_id' => $number->phone_number_id,
                        'display_number' => $number->display_number,
                        'bot_type' => $number->bot_type,
                        'source' => $number->source,
                    ]),
                // Renting skips the whole Meta setup, which is where most
                // resellers stall — so it is offered alongside, not buried.
                'rentable' => $this->rentableNumbers(),
                'rentals' => $this->activeRentals($request),
            ]),

            OnboardingStep::SetupPayments => Inertia::render('Onboarding/SetupPayments', [
                ...$shared,
                'gateways' => $this->gatewayOptions(),
                'connected' => $this->connectedGateways($request),
            ]),

            OnboardingStep::TestBot => Inertia::render('Onboarding/TestBot', [
                ...$shared,
                'simulator' => $this->simulatorProps($request),
                'simTested' => (bool) Arr::get(BotSettings::for($request->user()->id, 'order'), 'shop.sim_tested', false),
                ...TestBotStatus::for($request->user()->id)->toArray(),
                'botNumbers' => TenantWhatsApp::where('tenant_id', $request->user()->id)
                    ->get()
                    ->map(fn (TenantWhatsApp $number) => [
                        'id' => $number->id,
                        'display_number' => $number->display_number,
                        'phone_number_id' => $number->phone_number_id,
                    ]),
            ]),
        };
    }

    /**
     * What the in-browser WhatsApp screen needs to draw itself.
     *
     * @return array<string, mixed>
     */
    private function simulatorProps(Request $request): array
    {
        $tenant = $request->user();

        return [
            'endpoint' => route('simulator.send'),
            'business' => $tenant->business_name,
            'bots' => BotSimulator::BOTS,
            'startingBalance' => BotSimulator::STARTING_BALANCE,
        ];
    }

    /**
     * Numbers the platform has spare. The token is never included — the
     * reseller drives a rented number without ever holding the credential
     * that controls it.
     *
     * @return array<int, array>
     */
    private function rentableNumbers(): array
    {
        return PlatformNumber::where('status', 'available')
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
            ->all();
    }

    /** @return array<int, array> */
    private function activeRentals(Request $request): array
    {
        return NumberRental::where('tenant_id', $request->user()->id)
            ->where('status', 'active')
            ->with('platformNumber')
            ->get()
            ->map(fn (NumberRental $rental) => [
                'id' => $rental->id,
                'display_number' => $rental->platformNumber?->display_number,
                'country' => $rental->platformNumber?->country,
                'startedAt' => $rental->starts_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The gateway list as the form needs it: which credentials to ask for, and
     * whether choosing this one actually lets a customer pay yet.
     *
     * @return array<int, array>
     */
    private function gatewayOptions(): array
    {
        return array_values(array_map(fn (string $code) => [
            'code' => $code,
            'label' => Gateway::label($code),
            'type' => config("gateways.{$code}.type"),
            'ready' => Gateway::isReady($code),
            'fields' => array_map(fn (array $field) => [
                'name' => $field['name'],
                'label' => $field['label'],
            ], config("gateways.{$code}.fields", [])),
        ], array_keys(Gateway::all())));
    }

    /**
     * Credentials are never sent back to the browser — only the fact that a
     * gateway is connected, so the form can say "leave blank to keep".
     *
     * @return array<int, array>
     */
    private function connectedGateways(Request $request): array
    {
        return TenantPaymentGateway::where('tenant_id', $request->user()->id)
            ->where('status', 'active')
            ->get()
            ->map(fn (TenantPaymentGateway $row) => [
                'code' => $row->gateway,
                'label' => Gateway::label($row->gateway),
                'ready' => Gateway::isReady($row->gateway),
            ])
            ->all();
    }

    /**
     * Reading the catalogue means calling the panel, which can be slow or
     * down — so the page renders either way, and says what went wrong.
     */
    private function importServices(Request $request, array $shared): Response|RedirectResponse
    {
        $panel = TenantPanel::where('tenant_id', $request->user()->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        // No panel means the previous step is not really done; send them back.
        if ($panel === null) {
            return redirect()->route('onboarding');
        }

        $catalogue = $this->catalogue->forPanel($panel);

        return Inertia::render('Onboarding/ImportServices', [
            ...$shared,
            'panel' => [
                'id' => $panel->id,
                'name' => $panel->name,
            ],
            'services' => $catalogue->services,
            'catalogueError' => $catalogue->message,
        ]);
    }
}
