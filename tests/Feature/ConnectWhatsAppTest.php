<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Attaching a Cloud API number. phone_number_id is the routing key for every
 * inbound message, so the rules about who may claim one are load-bearing.
 */
class ConnectWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // Reach the WhatsApp step by finishing the two before it.
        TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);
        BotService::factory()->for($this->tenant)->create(['status' => 'active']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'phone_number_id' => '123456789',
            'token' => 'EAAG-permanent-token',
            'waba_id' => '987654321',
            'display_number' => '255700000000',
            'bot_type' => 'order',
            ...$overrides,
        ];
    }

    // ---- the page ---------------------------------------------------------

    public function test_it_shows_the_webhook_details_to_paste_into_meta(): void
    {
        config(['services.meta.verify_token' => 'my-verify-token']);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'whatsapp'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/ConnectWhatsApp')
                ->where('webhookUrl', route('webhooks.whatsapp'))
                ->where('verifyToken', 'my-verify-token')
                ->etc()
            );
    }

    public function test_it_lists_numbers_already_connected(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create([
            'display_number' => '255700000000',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'whatsapp'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('numbers', 1)->etc());
    }

    public function test_it_does_not_list_another_tenants_numbers(): void
    {
        $other = Tenant::factory()->create();
        TenantWhatsApp::factory()->for($other)->create();

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'whatsapp'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('numbers', 0)->etc());
    }

    // ---- connecting -------------------------------------------------------

    public function test_it_connects_a_number(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload())
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('tenant_whatsapp', [
            'tenant_id' => $this->tenant->id,
            'phone_number_id' => '123456789',
            'display_number' => '255700000000',
            'bot_type' => 'order',
            'source' => 'own',
            'status' => 'active',
        ]);
    }

    public function test_the_access_token_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload());

        $stored = TenantWhatsApp::withoutTenantScope()->sole();

        // Readable through the model, unreadable in the column.
        $this->assertSame('EAAG-permanent-token', $stored->cloud_api_token_enc);
        $this->assertStringNotContainsString(
            'EAAG-permanent-token',
            (string) $stored->getRawOriginal('cloud_api_token_enc'),
        );
    }

    public function test_connecting_completes_the_step(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload());

        // Payments is optional, so the next required stop is the bot test.
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding'))
            ->assertRedirect(route('onboarding.step', 'payments'));
    }

    public function test_reconnecting_the_same_number_updates_it(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload());

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload([
                'display_number' => '255700000999',
            ]));

        $this->assertDatabaseCount('tenant_whatsapp', 1);
        $this->assertDatabaseHas('tenant_whatsapp', ['display_number' => '255700000999']);
    }

    // ---- the rules that protect routing ------------------------------------

    public function test_a_number_claimed_by_another_tenant_is_refused(): void
    {
        // Two tenants on one phone_number_id would misroute real
        // conversations, since that id is how a webhook finds its owner.
        $other = Tenant::factory()->create();
        TenantWhatsApp::factory()->for($other)->create(['phone_number_id' => '123456789']);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload())
            ->assertSessionHasErrors('phone_number_id');

        $this->assertDatabaseMissing('tenant_whatsapp', [
            'tenant_id' => $this->tenant->id,
            'phone_number_id' => '123456789',
        ]);
    }

    public function test_two_numbers_cannot_both_run_the_order_bot(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create([
            'phone_number_id' => 'first-number',
            'bot_type' => 'order',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload([
                'phone_number_id' => 'second-number',
                'bot_type' => 'order',
            ]))
            ->assertSessionHasErrors('bot_type');
    }

    public function test_a_second_number_may_take_the_other_bot(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create([
            'phone_number_id' => 'order-number',
            'bot_type' => 'order',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload([
                'phone_number_id' => 'support-number',
                'bot_type' => 'support',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('tenant_whatsapp', 2);
    }

    public function test_a_number_cannot_claim_both_bots(): void
    {
        // A number is how an inbound message finds its bot, so it cannot mean
        // two things at once. Running both services takes two numbers.
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload(['bot_type' => 'both']))
            ->assertSessionHasErrors('bot_type');

        $this->assertDatabaseCount('tenant_whatsapp', 0);
    }

    public function test_an_unknown_bot_type_is_refused(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), $this->payload(['bot_type' => 'anything']))
            ->assertSessionHasErrors('bot_type');
    }

    public function test_the_id_and_token_are_both_required(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.store'), [
                'phone_number_id' => '',
                'token' => '',
                'bot_type' => 'order',
            ])
            ->assertSessionHasErrors(['phone_number_id', 'token']);
    }
}
