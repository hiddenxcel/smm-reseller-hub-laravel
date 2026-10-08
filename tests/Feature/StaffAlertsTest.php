<?php

namespace Tests\Feature;

use App\Models\BotMessage;
use App\Models\StaffAlert;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Notifications\StaffAlertEmail;
use App\Services\Bots\BotSettings;
use App\Services\Bots\BotSimulation;
use App\Services\Bots\StaffAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeBotMessenger;
use Tests\TestCase;

/**
 * Telling the reseller's team, and knowing whether it got through.
 *
 * WhatsApp delivers a free-form message only to someone who has written to the
 * bot in the last 24 hours, so a number on the list may be unreachable without
 * anyone knowing. These tests pin down that it is noticed, recorded, and
 * covered by email.
 */
class StaffAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private FakeBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        $this->tenant = Tenant::factory()->create(['email' => 'owner@example.test', 'business_name' => 'Kuza']);
        $this->messenger = new FakeBotMessenger;
    }

    private function team(array $numbers, array $extra = []): void
    {
        BotSettings::save($this->tenant->id, 'support', ['staff' => ['numbers' => $numbers] + $extra]);
    }

    private function wroteAgo(string $phone, int $hours): void
    {
        $row = BotMessage::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'customer_phone' => $phone,
            'direction' => 'in',
            'message' => 'hi',
            'bot_type' => 'support',
        ]);
        $row->forceFill(['created_at' => now()->subHours($hours)])->save();
    }

    private function alert(string $message = '🗑️ Cancel requested for *#1* by 255700000009'): void
    {
        app(StaffAlerts::class)->notify($this->tenant, 'support', $this->messenger, $message);
    }

    // ---- who can be reached ----------------------------------------------------

    public function test_a_number_that_wrote_recently_can_be_reached(): void
    {
        $this->wroteAgo('255700000001', 2);

        $this->assertSame('open', app(StaffAlerts::class)->reachability($this->tenant->id, '255700000001'));
    }

    public function test_a_number_that_last_wrote_over_a_day_ago_cannot(): void
    {
        $this->wroteAgo('255700000001', 30);

        $this->assertSame('closed', app(StaffAlerts::class)->reachability($this->tenant->id, '255700000001'));
    }

    public function test_a_number_that_never_wrote_cannot(): void
    {
        $this->assertSame('never', app(StaffAlerts::class)->reachability($this->tenant->id, '255700000001'));
    }

    public function test_numbers_are_compared_as_digits(): void
    {
        $this->team(['+255 700 000 001', '255700000001']);
        $this->wroteAgo('255700000001', 1);

        // One person, however the number was typed.
        $this->assertSame(['255700000001'], StaffAlerts::numbers($this->tenant->id, 'support'));
    }

    // ---- telling them ---------------------------------------------------------

    public function test_a_reachable_member_is_told_and_it_is_recorded(): void
    {
        $this->team(['255700000001']);
        $this->wroteAgo('255700000001', 1);

        $this->alert();

        $this->assertCount(1, $this->messenger->sent);
        $this->assertSame('255700000001', $this->messenger->sent[0]['to']);
        $this->assertDatabaseHas('staff_alerts', ['tenant_id' => $this->tenant->id, 'to_phone' => '255700000001', 'status' => 'sent', 'reason' => null]);
    }

    public function test_an_unreachable_member_is_not_sent_to_and_the_reason_is_recorded(): void
    {
        $this->team(['255700000001', '255700000002', '255700000003']);
        $this->wroteAgo('255700000001', 1);
        $this->wroteAgo('255700000002', 40);

        $this->alert();

        // Only the one who can be reached is sent anything: nothing is left in
        // the history that looks delivered but was not.
        $this->assertSame(['255700000001'], array_column($this->messenger->sent, 'to'));
        $this->assertSame('closed', StaffAlert::where('to_phone', '255700000002')->value('reason'));
        $this->assertSame('never', StaffAlert::where('to_phone', '255700000003')->value('reason'));
        $this->assertSame(2, StaffAlert::where('status', 'failed')->count());
    }

    public function test_a_refusal_from_whatsapp_is_recorded_as_such(): void
    {
        $this->team(['255700000001']);
        $this->wroteAgo('255700000001', 1);
        $this->messenger->failFor = ['255700000001'];

        $this->alert();

        $this->assertDatabaseHas('staff_alerts', ['to_phone' => '255700000001', 'status' => 'failed', 'reason' => 'rejected']);
    }

    // ---- email as the safety net ----------------------------------------------

    public function test_by_default_email_is_sent_only_when_whatsapp_could_not_reach_everyone(): void
    {
        $this->team(['255700000001']);
        $this->wroteAgo('255700000001', 1);

        $this->alert();
        Notification::assertNothingSent();

        $this->team(['255700000001', '255700000002']);
        $this->alert();

        Notification::assertSentTo($this->tenant, StaffAlertEmail::class);
    }

    public function test_email_covers_a_team_that_has_no_numbers_at_all(): void
    {
        $this->team([]);

        $this->alert();

        Notification::assertSentTo($this->tenant, StaffAlertEmail::class);
    }

    public function test_the_email_says_who_was_missed_and_why(): void
    {
        $this->team(['255700000002']);

        $this->alert();

        Notification::assertSentTo($this->tenant, StaffAlertEmail::class, function (StaffAlertEmail $mail) {
            $text = implode("\n", $mail->toMail($this->tenant)->introLines);

            return str_contains($text, 'Cancel requested for #1')
                && str_contains($text, '255700000002: has never messaged the bot');
        });
    }

    public function test_email_can_be_sent_for_every_alert(): void
    {
        $this->team(['255700000001'], ['email' => 'all']);
        $this->wroteAgo('255700000001', 1);

        $this->alert();

        Notification::assertSentTo($this->tenant, StaffAlertEmail::class);
    }

    public function test_email_can_be_switched_off(): void
    {
        $this->team([], ['email' => 'off']);

        $this->alert();

        Notification::assertNothingSent();
    }

    public function test_a_rehearsal_in_the_simulator_records_and_emails_nothing(): void
    {
        $this->team(['255700000001']);

        BotSimulation::run(fn () => $this->alert());

        $this->assertSame(0, StaffAlert::count());
        Notification::assertNothingSent();
        // The simulator still shows the alert, as it always did.
        $this->assertCount(1, $this->messenger->sent);
    }

    // ---- the test button ---------------------------------------------------------

    private function connectSupportNumber(): TenantWhatsApp
    {
        return TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'support', 'status' => 'active']);
    }

    public function test_a_test_alert_reaches_a_member_who_can_be_told(): void
    {
        $this->team(['255700000001']);
        $this->wroteAgo('255700000001', 1);
        $this->connectSupportNumber();

        $this->actingAs($this->tenant)
            ->post(route('staff-alerts.test', 'support'), ['phone' => '255700000001'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $sent = Http::recorded(fn (Request $request) => str_contains($request->url(), '/messages'));
        $this->assertCount(1, $sent);
        $this->assertDatabaseHas('staff_alerts', ['to_phone' => '255700000001', 'is_test' => true, 'status' => 'sent']);
    }

    public function test_a_test_to_someone_who_cannot_be_told_says_why_and_sends_nothing(): void
    {
        $this->team(['255700000001']);
        $this->connectSupportNumber();

        $this->actingAs($this->tenant)
            ->post(route('staff-alerts.test', 'support'), ['phone' => '255700000001'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'has never messaged the bot'));

        Http::assertNothingSent();
    }

    public function test_a_test_can_only_go_to_a_number_on_the_team_list(): void
    {
        $this->team(['255700000001']);
        $this->wroteAgo('255700000009', 1);
        $this->connectSupportNumber();

        $this->actingAs($this->tenant)
            ->post(route('staff-alerts.test', 'support'), ['phone' => '255700000009'])
            ->assertSessionHas('error', 'That number is not on your team list.');

        Http::assertNothingSent();
    }

    public function test_the_email_choice_is_saved_and_validated(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('staff-alerts.email', 'support'), ['mode' => 'all'])
            ->assertSessionHas('success');

        $this->assertSame('all', BotSettings::for($this->tenant->id, 'support')['staff']['email']);

        $this->actingAs($this->tenant)
            ->post(route('staff-alerts.email', 'support'), ['mode' => 'sometimes'])
            ->assertSessionHasErrors('mode');
    }

    public function test_an_unknown_bot_is_not_a_page(): void
    {
        $this->actingAs($this->tenant)->post(route('staff-alerts.email', 'support').'x', ['mode' => 'all'])->assertNotFound();
    }

    // ---- what the settings screens show ---------------------------------------------

    public function test_both_bots_settings_show_who_can_be_told_and_what_was_sent(): void
    {
        $this->team(['255700000001', '255700000002']);
        $this->wroteAgo('255700000001', 1);
        $this->alert();

        foreach (['support-bot' => 'settings.staffAlerts', 'order-bot' => 'settings.staffAlerts'] as $route => $prop) {
            $this->actingAs($this->tenant)
                ->get(route($route, 'settings'))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where($prop.'.emailMode', 'failed')
                    ->where($prop.'.email', 'owner@example.test')
                    ->has($prop.'.recent'));
        }

        $this->actingAs($this->tenant)
            ->get(route('support-bot', 'settings'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.staffAlerts.numbers.0.state', 'open')
                ->where('settings.staffAlerts.numbers.1.state', 'never'));
    }
}
