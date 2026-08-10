<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Notifications\PanelBalanceLow;
use App\Notifications\PanelRecovered;
use App\Notifications\PanelWentDown;
use App\Services\Panel\PanelHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A panel going down is the failure a reseller cannot see for themselves, so
 * the value of this is entirely in the notification firing — and firing once.
 *
 * The check runs every five minutes. A notification sent on state rather than
 * on transition would be around 288 emails a day per dead panel, which is not
 * a louder warning but a silent one: nobody reads the 40th.
 */
class PanelHealthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->tenant = Tenant::factory()->create();
    }

    private function panel(array $attributes = []): TenantPanel
    {
        return TenantPanel::factory()
            ->for($this->tenant)
            ->create($attributes);
    }

    private function health(): PanelHealth
    {
        return app(PanelHealth::class);
    }

    /** The SMM API reports failure in the body, not the HTTP status. */
    private function fakePanelError(string $message = 'Invalid API key'): void
    {
        Http::fake(['*' => Http::response(['error' => $message])]);
    }

    private function fakePanelBalance(string $balance, string $currency = 'USD'): void
    {
        Http::fake(['*' => Http::response(['balance' => $balance, 'currency' => $currency])]);
    }

    // ---- going down ------------------------------------------------------

    public function test_a_panel_that_stops_answering_is_marked_down_and_the_reseller_told(): void
    {
        $panel = $this->panel(['status' => 'active']);

        $this->fakePanelError();

        $result = $this->health()->check($panel);

        $this->assertFalse($result->healthy);
        $this->assertTrue($result->changed);
        $this->assertSame('error', $panel->fresh()->status);

        Notification::assertSentTo($this->tenant, PanelWentDown::class);
    }

    public function test_a_panel_that_is_already_down_does_not_notify_again(): void
    {
        $panel = $this->panel(['status' => 'error']);

        $this->fakePanelError();

        $result = $this->health()->check($panel);

        $this->assertFalse($result->healthy);
        // Still down, but nothing changed — so nothing to say.
        $this->assertFalse($result->changed);

        Notification::assertNothingSent();
    }

    /**
     * The last balance we genuinely read is more useful than null, as long as
     * last_checked_at says how old it is.
     */
    public function test_a_failed_check_keeps_the_last_known_balance(): void
    {
        $panel = $this->panel(['status' => 'active', 'last_balance' => '42.00']);

        $this->fakePanelError();

        $this->health()->check($panel);

        $this->assertSame('42.00', $panel->fresh()->last_balance);
        $this->assertNotNull($panel->fresh()->last_checked_at);
    }

    // ---- coming back -----------------------------------------------------

    public function test_a_recovered_panel_is_marked_active_and_the_reseller_told(): void
    {
        $panel = $this->panel(['status' => 'error']);

        $this->fakePanelBalance('250.00');

        $result = $this->health()->check($panel);

        $this->assertTrue($result->healthy);
        $this->assertTrue($result->changed);
        $this->assertSame('active', $panel->fresh()->status);
        $this->assertSame('250.00', $panel->fresh()->last_balance);

        Notification::assertSentTo($this->tenant, PanelRecovered::class);
    }

    public function test_a_healthy_panel_that_stays_healthy_says_nothing(): void
    {
        $panel = $this->panel(['status' => 'active', 'last_balance' => '250.00']);

        $this->fakePanelBalance('240.00');

        $result = $this->health()->check($panel);

        $this->assertTrue($result->healthy);
        $this->assertFalse($result->changed);

        Notification::assertNothingSent();
    }

    // ---- running out of money -------------------------------------------

    public function test_a_balance_falling_below_the_floor_warns_the_reseller(): void
    {
        $panel = $this->panel(['status' => 'active', 'last_balance' => '50.00']);

        $this->fakePanelBalance('3.00');

        $this->health()->check($panel);

        Notification::assertSentTo($this->tenant, PanelBalanceLow::class);
    }

    /**
     * The warning is worth sending once. A panel left below the floor for a
     * week must not generate a week of identical email.
     */
    public function test_a_balance_already_low_does_not_warn_again(): void
    {
        $panel = $this->panel(['status' => 'active', 'last_balance' => '2.00']);

        $this->fakePanelBalance('1.50');

        $this->health()->check($panel);

        Notification::assertNotSentTo($this->tenant, PanelBalanceLow::class);
    }

    public function test_a_healthy_balance_does_not_warn(): void
    {
        $panel = $this->panel(['status' => 'active', 'last_balance' => '500.00']);

        $this->fakePanelBalance('480.00');

        $this->health()->check($panel);

        Notification::assertNotSentTo($this->tenant, PanelBalanceLow::class);
    }

    // ---- the threshold is the reseller's to set --------------------------

    /**
     * A reseller running a large float needs warning long before $5. The
     * default is only what applies until they say otherwise.
     */
    public function test_a_reseller_can_be_warned_at_their_own_figure(): void
    {
        $panel = $this->panel([
            'status' => 'active',
            'last_balance' => '900.00',
            'low_balance_threshold' => '500.00',
        ]);

        $this->fakePanelBalance('420.00');

        $this->health()->check($panel);

        Notification::assertSentTo($this->tenant, PanelBalanceLow::class);
    }

    /** The same balance is unremarkable on a panel with a low threshold. */
    public function test_the_same_balance_does_not_warn_on_a_panel_set_lower(): void
    {
        $panel = $this->panel([
            'status' => 'active',
            'last_balance' => '900.00',
            'low_balance_threshold' => '100.00',
        ]);

        $this->fakePanelBalance('420.00');

        $this->health()->check($panel);

        Notification::assertNotSentTo($this->tenant, PanelBalanceLow::class);
    }

    /**
     * Two panels held by one reseller, warned at their own figures — the whole
     * reason this is per panel rather than per account.
     */
    public function test_each_panel_is_judged_on_its_own_threshold(): void
    {
        $big = $this->panel([
            'name' => 'Main',
            'last_balance' => '900.00',
            'low_balance_threshold' => '500.00',
        ]);

        $small = $this->panel([
            'name' => 'Side',
            'last_balance' => '900.00',
            'low_balance_threshold' => '20.00',
        ]);

        $this->fakePanelBalance('420.00');

        $this->health()->check($big);
        $this->health()->check($small);

        Notification::assertSentToTimes($this->tenant, PanelBalanceLow::class, 1);
    }

    /** An unset threshold still warns — null means default, not "never". */
    public function test_a_panel_with_no_threshold_falls_back_to_the_default(): void
    {
        $panel = $this->panel([
            'status' => 'active',
            'last_balance' => '50.00',
            'low_balance_threshold' => null,
        ]);

        $this->fakePanelBalance('2.00');

        $this->health()->check($panel);

        Notification::assertSentTo($this->tenant, PanelBalanceLow::class);
    }

    // ---- the scheduled command ------------------------------------------

    public function test_the_command_checks_panels_and_reports_what_changed(): void
    {
        $this->panel(['status' => 'active', 'name' => 'Main Panel']);

        $this->fakePanelError();

        $this->artisan('panels:check')
            ->expectsOutputToContain('Down: Main Panel')
            ->expectsOutputToContain('1 down')
            ->assertSuccessful();
    }

    /**
     * A suspended reseller is not selling, so their panel going down is not an
     * event anyone needs an email about.
     */
    public function test_the_command_skips_suspended_resellers(): void
    {
        $this->tenant->update(['status' => 'suspended']);
        $this->panel(['status' => 'active']);

        $this->fakePanelError();

        $this->artisan('panels:check')
            ->expectsOutputToContain('No panels to check.')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    /**
     * One bad panel must not stop the rest of the run — every panel after it
     * would otherwise go unchecked.
     */
    public function test_one_panel_throwing_does_not_stop_the_others(): void
    {
        $this->panel(['name' => 'Broken', 'api_url' => 'not-a-url']);
        $this->panel(['name' => 'Fine']);

        Http::fake([
            'not-a-url*' => fn () => throw new \RuntimeException('bad host'),
            '*' => Http::response(['balance' => '100.00', 'currency' => 'USD']),
        ]);

        $this->artisan('panels:check')->assertSuccessful();

        $this->assertSame(2, TenantPanel::withoutTenantScope()->count());
    }
}
