<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotSettings;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Putting a step off for later.
 *
 * The line this has to hold: skipping moves the reseller on without moving
 * the shop on. A skipped step is passed over by the wizard and by nothing
 * else — going live still refuses, the dashboard still asks, and the
 * settings tab is still flagged. Anything looser and "skip" quietly becomes
 * "done", which would put a shop live that cannot sell.
 */
class SkipStepTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // These tests are about the steps that ask for something. The practice
        // chat that opens the wizard is covered in SimulatorTest.
        $settings = BotSettings::for($this->tenant->id, 'order');
        Arr::set($settings, 'shop.bot_tried', true);
        BotSettings::save($this->tenant->id, 'order', $settings);
    }

    private function skipped(): array
    {
        return Arr::get(
            BotSettings::for($this->tenant->id, 'order'),
            'shop.skipped_steps',
            [],
        );
    }

    public function test_skipping_moves_past_the_step(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.skip', 'panel'))
            ->assertRedirect(route('onboarding'));

        $this->assertSame(['panel'], $this->skipped());

        // The wizard now offers the next step instead of the skipped one. Not
        // the import step: it builds on the panel that was just put off.
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding'))
            ->assertRedirect(route('onboarding.step', OnboardingStep::ConnectWhatsApp->value));
    }

    public function test_a_skipped_step_is_not_a_finished_step(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'panel'));

        $progress = OnboardingProgress::for($this->tenant->fresh());

        $this->assertTrue($progress->isSkipped(OnboardingStep::ConnectPanel));
        $this->assertFalse($progress->isComplete(OnboardingStep::ConnectPanel));
        $this->assertSame(1, $progress->completedCount()); // the practice chat
        $this->assertFalse($progress->isReadyToGoLive());
    }

    public function test_skipping_everything_does_not_make_a_shop_ready_to_go_live(): void
    {
        foreach (['panel', 'services', 'whatsapp', 'payments', 'test'] as $step) {
            $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', $step));
        }

        $progress = OnboardingProgress::for($this->tenant->fresh());

        $this->assertFalse($progress->isReadyToGoLive());
        $this->assertCount(4, $progress->outstanding());
    }

    public function test_continuing_after_skipping_the_panel_does_not_loop(): void
    {
        // The reported case: panel skipped, services not. Import needs a panel,
        // so "Continue setup" used to bounce between the two for ever.
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'panel'));

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding'))
            ->assertRedirect(route('onboarding.step', 'whatsapp'));
    }

    public function test_opening_the_import_step_without_a_panel_goes_to_the_panel_step(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'services'))
            ->assertRedirect(route('onboarding.step', 'panel'));
    }

    public function test_the_import_step_stays_outstanding_while_it_is_passed_over(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'panel'));

        $progress = OnboardingProgress::for($this->tenant->fresh());

        $this->assertTrue($progress->isBlocked(OnboardingStep::ImportServices));
        $this->assertFalse($progress->isComplete(OnboardingStep::ImportServices));
        $this->assertContains(OnboardingStep::ImportServices, $progress->outstanding());
    }

    public function test_the_import_step_is_offered_again_once_the_panel_is_connected(): void
    {
        TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);

        $progress = OnboardingProgress::for($this->tenant->fresh());

        $this->assertFalse($progress->isBlocked(OnboardingStep::ImportServices));
        $this->assertSame(OnboardingStep::ImportServices, $progress->currentStep());
    }

    public function test_skipping_the_last_remaining_step_lands_on_the_dashboard(): void
    {
        // Nothing left for the wizard to show means it has no next screen.
        foreach (['panel', 'services', 'whatsapp', 'payments'] as $step) {
            $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', $step));
        }

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.skip', 'test'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_skipped_step_can_be_picked_back_up(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'panel'));

        $this->actingAs($this->tenant, 'tenant')
            ->delete(route('onboarding.unskip', 'panel'))
            ->assertRedirect(route('onboarding.step', 'panel'));

        $this->assertSame([], $this->skipped());
    }

    public function test_skipping_the_same_step_twice_records_it_once(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'panel'));
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'panel'));

        $this->assertSame(['panel'], $this->skipped());
    }

    public function test_an_unknown_step_cannot_be_skipped(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.skip', 'nonsense'))
            ->assertRedirect(route('onboarding'));

        $this->assertSame([], $this->skipped());
    }

    public function test_doing_a_skipped_step_anyway_completes_it(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'panel'));

        TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);

        $progress = OnboardingProgress::for($this->tenant->fresh());

        // Skipped and complete are independent: the data decides completion.
        $this->assertTrue($progress->isComplete(OnboardingStep::ConnectPanel));
        $this->assertSame(2, $progress->completedCount());
    }

    public function test_the_wizard_page_reports_which_steps_were_skipped(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'panel'));

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'panel'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.1.skipped', true)
                ->where('steps.1.complete', false)
                ->where('steps.2.skipped', false));
    }

    public function test_a_finished_step_offers_nothing_to_skip(): void
    {
        TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'panel'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canSkip', false));
    }

    public function test_skipping_needs_a_login(): void
    {
        $this->post(route('onboarding.skip', 'panel'))->assertRedirect(route('login'));
    }

    public function test_one_tenants_skip_does_not_touch_another(): void
    {
        $other = Tenant::factory()->create();

        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'panel'));

        $this->assertSame([], Arr::get(
            BotSettings::for($other->id, 'order'),
            'shop.skipped_steps',
            [],
        ));
    }

    public function test_the_dashboard_still_asks_for_a_skipped_step(): void
    {
        $panel = TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);
        BotService::factory()->for($this->tenant)->create([
            'panel_id' => $panel->id,
            'status' => 'active',
        ]);
        TenantWhatsApp::factory()->for($this->tenant)->create();

        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.skip', 'test'));

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('setup.readyToGoLive', false)
                ->where('setup.steps.5.skipped', true)
                ->where('setup.steps.5.complete', false));
    }
}
