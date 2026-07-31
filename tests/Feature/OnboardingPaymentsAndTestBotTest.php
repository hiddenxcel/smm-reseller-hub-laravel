<?php

namespace Tests\Feature;

use App\Models\BotMessage;
use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantPaymentGateway;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotSettings;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The last two wizard steps: plugging in a gateway, and proving the bot works
 * before the shop opens to real customers.
 */
class OnboardingPaymentsAndTestBotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    /** Everything before the payments step, so the wizard will render it. */
    private function completeEarlierSteps(): void
    {
        TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);
        BotService::factory()->for($this->tenant)->create(['status' => 'active']);
        TenantWhatsApp::factory()->for($this->tenant)->create();
    }

    private function logMessage(string $direction): BotMessage
    {
        return BotMessage::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'customer_phone' => '255700000000',
            'direction' => $direction,
            'message' => $direction === 'in' ? 'hi' : 'Welcome to the shop',
            'bot_type' => 'order',
        ]);
    }

    // ---- setting up payments ---------------------------------------------

    public function test_the_payments_step_lists_gateways_without_leaking_keys(): void
    {
        $this->completeEarlierSteps();

        TenantPaymentGateway::factory()->for($this->tenant)->create([
            'gateway' => 'snippe',
            'api_key_enc' => 'super-secret',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', OnboardingStep::SetupPayments->value));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Onboarding/SetupPayments')
            ->has('gateways')
            ->has('connected', 1)
            ->where('connected.0.code', 'snippe')
        );

        // The stored key must never reach the browser.
        $response->assertDontSee('super-secret');
    }

    public function test_it_stores_gateway_credentials_and_completes_the_step(): void
    {
        $this->assertFalse(
            OnboardingProgress::for($this->tenant)->isComplete(OnboardingStep::SetupPayments)
        );

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.payments.store'), [
                'gateway' => 'snippe',
                'credentials' => [
                    'api_key' => 'live-key',
                    'webhook_secret' => 'live-secret',
                ],
            ])
            ->assertRedirect(route('onboarding'));

        $stored = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        $this->assertSame('snippe', $stored->gateway);
        $this->assertSame('live-key', $stored->api_key_enc);
        $this->assertSame('live-secret', $stored->webhook_secret_enc);

        $this->assertTrue(
            OnboardingProgress::for($this->tenant)->isComplete(OnboardingStep::SetupPayments)
        );
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.payments.store'), [
            'gateway' => 'snippe',
            'credentials' => ['api_key' => 'live-key', 'webhook_secret' => 'live-secret'],
        ]);

        $raw = $this->getConnection()
            ->table('tenant_payment_gateways')
            ->where('tenant_id', $this->tenant->id)
            ->value('api_key_enc');

        $this->assertNotSame('live-key', $raw);
    }

    public function test_a_blank_field_keeps_the_stored_credential(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.payments.store'), [
            'gateway' => 'snippe',
            'credentials' => ['api_key' => 'first-key', 'webhook_secret' => 'first-secret'],
        ]);

        // The form never sends the stored secret back, so blank must mean
        // "leave it alone" rather than "delete it".
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.payments.store'), [
            'gateway' => 'snippe',
            'credentials' => ['api_key' => 'second-key', 'webhook_secret' => ''],
        ]);

        $stored = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        $this->assertSame('second-key', $stored->api_key_enc);
        $this->assertSame('first-secret', $stored->webhook_secret_enc);
    }

    public function test_a_blank_field_on_a_new_gateway_is_rejected(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.payments.store'), [
                'gateway' => 'snippe',
                'credentials' => ['api_key' => 'key-only', 'webhook_secret' => ''],
            ])
            ->assertSessionHasErrors('credentials.webhook_secret');

        $this->assertDatabaseCount('tenant_payment_gateways', 0);
    }

    public function test_an_unknown_gateway_is_rejected(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.payments.store'), [
                'gateway' => 'not-a-gateway',
                'credentials' => ['api_key' => 'key'],
            ])
            ->assertSessionHasErrors('gateway');

        $this->assertDatabaseCount('tenant_payment_gateways', 0);
    }

    public function test_disconnecting_keeps_the_keys_but_undoes_the_step(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.payments.store'), [
            'gateway' => 'snippe',
            'credentials' => ['api_key' => 'key', 'webhook_secret' => 'secret'],
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->delete(route('onboarding.payments.destroy', 'snippe'))
            ->assertRedirect(route('onboarding.step', 'payments'));

        $stored = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        $this->assertSame('inactive', $stored->status);
        $this->assertSame('key', $stored->api_key_enc);

        $this->assertFalse(
            OnboardingProgress::for($this->tenant)->isComplete(OnboardingStep::SetupPayments)
        );
    }

    public function test_disconnecting_cannot_reach_another_tenants_gateway(): void
    {
        $other = Tenant::factory()->create();
        TenantPaymentGateway::factory()->for($other)->create([
            'gateway' => 'snippe',
            'status' => 'active',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->delete(route('onboarding.payments.destroy', 'snippe'));

        $this->assertSame(
            'active',
            TenantPaymentGateway::withoutTenantScope()
                ->where('tenant_id', $other->id)
                ->value('status'),
        );
    }

    // ---- testing the bot --------------------------------------------------

    public function test_the_test_step_reports_what_the_bot_has_actually_done(): void
    {
        $this->completeEarlierSteps();
        $this->logMessage('in');

        $response = $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', OnboardingStep::TestBot->value));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Onboarding/TestBot')
            ->where('messageReceived', true)
            ->where('botReplied', false)
            ->where('orderPlaced', false)
            ->has('recentMessages', 1)
        );
    }

    public function test_it_registers_a_test_number_the_bot_will_recognise(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.test.number.store'), ['phone' => '+255 700 000 000'])
            ->assertRedirect(route('onboarding.step', 'test'));

        // Stored as digits, which is what the sandbox gate compares against.
        $this->assertSame(
            ['255700000000'],
            Arr::get(BotSettings::for($this->tenant->id, 'order'), 'shop.test_numbers'),
        );

        $this->assertTrue(BotSettings::isTestNumber($this->tenant->id, '255700000000'));
    }

    public function test_the_same_number_is_not_added_twice(): void
    {
        foreach (['255700000000', '+255-700-000-000'] as $phone) {
            $this->actingAs($this->tenant, 'tenant')
                ->post(route('onboarding.test.number.store'), ['phone' => $phone]);
        }

        $this->assertCount(
            1,
            Arr::get(BotSettings::for($this->tenant->id, 'order'), 'shop.test_numbers'),
        );
    }

    public function test_a_number_with_no_digits_is_rejected(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.test.number.store'), ['phone' => 'call me'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(
            [],
            Arr::get(BotSettings::for($this->tenant->id, 'order'), 'shop.test_numbers'),
        );
    }

    public function test_a_test_number_can_be_removed(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.test.number.store'), ['phone' => '255700000000']);

        $this->actingAs($this->tenant, 'tenant')
            ->delete(route('onboarding.test.number.destroy', '255700000000'));

        $this->assertSame(
            [],
            Arr::get(BotSettings::for($this->tenant->id, 'order'), 'shop.test_numbers'),
        );
    }

    public function test_going_live_is_refused_until_the_bot_has_answered(): void
    {
        // An inbound message alone proves the webhook fired, not that the bot
        // works — going live on that would open a shop that cannot reply.
        $this->logMessage('in');

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.test.golive'))
            ->assertSessionHasErrors('go_live');

        $this->assertFalse(
            OnboardingProgress::for($this->tenant)->isComplete(OnboardingStep::TestBot)
        );
    }

    public function test_going_live_completes_the_wizard_once_the_bot_has_replied(): void
    {
        $this->completeEarlierSteps();
        TenantPaymentGateway::factory()->for($this->tenant)->create([
            'gateway' => 'snippe',
            'status' => 'active',
        ]);

        $this->logMessage('in');
        $this->logMessage('out');

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.test.golive'))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(
            OnboardingProgress::for($this->tenant)->isComplete(OnboardingStep::TestBot)
        );
    }

    public function test_going_live_keeps_settings_the_reseller_already_had(): void
    {
        BotSettings::save($this->tenant->id, 'order', [
            'shop' => ['currency' => 'TZS', 'test_numbers' => ['255700000000']],
        ]);

        $this->logMessage('out');

        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.test.golive'));

        $settings = BotSettings::for($this->tenant->id, 'order');

        $this->assertTrue(Arr::get($settings, 'shop.bot_tested'));
        $this->assertSame('TZS', Arr::get($settings, 'shop.currency'));
        $this->assertSame(['255700000000'], Arr::get($settings, 'shop.test_numbers'));
    }

    public function test_another_tenants_messages_do_not_unlock_your_go_live(): void
    {
        $other = Tenant::factory()->create();
        BotMessage::withoutTenantScope()->create([
            'tenant_id' => $other->id,
            'customer_phone' => '255700000001',
            'direction' => 'out',
            'message' => 'Welcome',
            'bot_type' => 'order',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.test.golive'))
            ->assertSessionHasErrors('go_live');
    }

    public function test_both_new_steps_need_a_login(): void
    {
        $this->post(route('onboarding.payments.store'))->assertRedirect(route('login'));
        $this->post(route('onboarding.test.golive'))->assertRedirect(route('login'));
    }
}
