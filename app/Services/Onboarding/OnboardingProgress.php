<?php

namespace App\Services\Onboarding;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotSettings;
use App\Services\Payments\Gateway;
use Illuminate\Support\Arr;

/**
 * How far a reseller has got in setting up their shop.
 *
 * Completion is derived from the data itself rather than stored as flags:
 * a step is done when the thing it produces exists. That way progress can
 * never drift from reality — deleting your only panel puts you back on step
 * one, which is correct.
 */
class OnboardingProgress
{
    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function isComplete(OnboardingStep $step): bool
    {
        return match ($step) {
            // Done once they have tried it — or once they are clearly past
            // needing to. A reseller who set a shop up before this step
            // existed should not be sent back to a demo.
            OnboardingStep::TryBot => (bool) Arr::get(
                BotSettings::for($this->tenant->id, 'order'),
                'shop.bot_tried',
                false,
            ) || $this->hasProgressedPast(),

            OnboardingStep::ConnectPanel => $this->tenant->panels()
                ->where('status', 'active')
                ->exists(),

            OnboardingStep::ImportServices => BotService::withoutTenantScope()
                ->where('tenant_id', $this->tenant->id)
                ->where('status', 'active')
                ->exists(),

            OnboardingStep::ConnectWhatsApp => TenantWhatsApp::withoutTenantScope()
                ->where('tenant_id', $this->tenant->id)
                ->exists(),

            OnboardingStep::SetupPayments => $this->hasUsableGateway(),

            // Nothing marks the test as done but the reseller saying so —
            // we cannot tell a real conversation from a test one.
            OnboardingStep::TestBot => (bool) Arr::get(
                BotSettings::for($this->tenant->id, 'order'),
                'shop.bot_tested',
                false,
            ),
        };
    }

    /**
     * The next thing to do, or null when nothing is left to show.
     *
     * A skipped step is passed over here but is NOT complete: the reseller
     * said "later", not "done". Everything that reads completion — going
     * live, the dashboard's setup card, the settings tabs — still sees it as
     * outstanding, so a skip can never quietly become a finished shop.
     */
    public function currentStep(): ?OnboardingStep
    {
        foreach (OnboardingStep::ordered() as $step) {
            if (! $this->isComplete($step) && ! $this->isSkipped($step)) {
                return $step;
            }
        }

        return null;
    }

    /** Steps the reseller chose to come back to later. */
    public function isSkipped(OnboardingStep $step): bool
    {
        return in_array($step->value, $this->skippedSteps(), true);
    }

    /** @return array<int, string> */
    public function skippedSteps(): array
    {
        return array_values(Arr::get(
            BotSettings::for($this->tenant->id, 'order'),
            'shop.skipped_steps',
            [],
        ));
    }

    /**
     * Required steps that are neither done nor skipped — what actually
     * stands between the reseller and a working shop.
     *
     * @return array<int, OnboardingStep>
     */
    public function outstanding(): array
    {
        return array_values(array_filter(
            OnboardingStep::ordered(),
            fn (OnboardingStep $step) => $step->isRequired() && ! $this->isComplete($step),
        ));
    }

    /** Whether the reseller can go live, ignoring optional steps. */
    public function isReadyToGoLive(): bool
    {
        foreach (OnboardingStep::ordered() as $step) {
            if ($step->isRequired() && ! $this->isComplete($step)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array{key: string, title: string, description: string, complete: bool, required: bool, skipped: bool}> */
    public function toArray(): array
    {
        return array_map(fn (OnboardingStep $step) => [
            'key' => $step->value,
            'title' => $step->title(),
            'description' => $step->description(),
            'complete' => $this->isComplete($step),
            'required' => $step->isRequired(),
            'skipped' => $this->isSkipped($step),
        ], OnboardingStep::ordered());
    }

    public function completedCount(): int
    {
        return count(array_filter(
            OnboardingStep::ordered(),
            fn (OnboardingStep $step) => $this->isComplete($step),
        ));
    }

    /** Has any real setup step been finished? */
    private function hasProgressedPast(): bool
    {
        foreach (OnboardingStep::ordered() as $step) {
            if ($step !== OnboardingStep::TryBot && $step !== OnboardingStep::TestBot && $this->isComplete($step)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A gateway only counts once it is one we can actually drive — storing
     * keys for a "coming soon" gateway does not let anyone pay.
     */
    private function hasUsableGateway(): bool
    {
        return TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('status', 'active')
            ->pluck('gateway')
            ->contains(fn (string $gateway) => Gateway::isReady($gateway));
    }
}
