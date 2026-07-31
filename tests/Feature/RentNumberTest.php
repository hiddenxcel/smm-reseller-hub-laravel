<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\BotService;
use App\Models\NumberRental;
use App\Models\PlatformNumber;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantWhatsApp;
use App\Services\Numbers\RentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

/**
 * Renting exists so a reseller can skip the Meta setup entirely. The platform
 * keeps the app and the token; they get a number that already works.
 *
 * Paid once, so there is no expiry and nothing sweeps rentals — the number is
 * theirs until they hand it back.
 */
class RentNumberTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function rent(): RentNumber
    {
        return app(RentNumber::class);
    }

    // ---- claiming --------------------------------------------------------

    public function test_renting_attaches_a_working_number_to_the_tenant(): void
    {
        $number = PlatformNumber::factory()->create([
            'display_number' => '255700000999',
            'phone_number_id' => 'platform-1',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.rent'), [
                'platform_number_id' => $number->id,
                'bot_type' => 'both',
            ])
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('tenant_whatsapp', [
            'tenant_id' => $this->tenant->id,
            'phone_number_id' => 'platform-1',
            'display_number' => '255700000999',
            'source' => 'rented',
            'bot_type' => 'both',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('number_rentals', [
            'tenant_id' => $this->tenant->id,
            'platform_number_id' => $number->id,
            'status' => 'active',
        ]);
    }

    public function test_the_platform_token_is_copied_but_never_exposed(): void
    {
        $number = PlatformNumber::factory()->create([
            'cloud_api_token_enc' => 'the-platform-token',
        ]);

        $this->rent()->claim($this->tenant, $number->id, 'order');

        $attached = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        // The bot needs the token to reply...
        $this->assertSame('the-platform-token', $attached->cloud_api_token_enc);

        // ...but the reseller must never receive it.
        $this->assertArrayNotHasKey('cloud_api_token_enc', $attached->toArray());
    }

    public function test_renting_marks_the_number_as_taken(): void
    {
        $number = PlatformNumber::factory()->create();

        $this->rent()->claim($this->tenant, $number->id, 'both');

        $this->assertSame('rented', $number->fresh()->status);
    }

    public function test_a_rental_never_expires(): void
    {
        // Bought outright, so nothing sweeps it and no missed renewal can cut
        // a reseller off from their own customers.
        $number = PlatformNumber::factory()->create();

        $rental = $this->rent()->claim($this->tenant, $number->id, 'both');

        $this->assertNull($rental->ends_at);
    }

    public function test_renting_opens_the_number_rental_service(): void
    {
        $number = PlatformNumber::factory()->create();

        $this->rent()->claim($this->tenant, $number->id, 'both');

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $this->tenant->id,
            'service_key' => ServiceKey::NumberRental->value,
            'status' => 'active',
        ]);
    }

    // ---- two resellers, one number ---------------------------------------

    public function test_a_number_already_rented_cannot_be_claimed_again(): void
    {
        $number = PlatformNumber::factory()->rented()->create();

        $this->expectException(RuntimeException::class);

        $this->rent()->claim($this->tenant, $number->id, 'both');
    }

    public function test_losing_the_race_leaves_nothing_behind(): void
    {
        // Two resellers can be looking at the same number; only one may walk
        // away with it, and the loser must not end up with a half-made rental.
        $number = PlatformNumber::factory()->create();
        $other = Tenant::factory()->create();

        $this->rent()->claim($other, $number->id, 'both');

        try {
            $this->rent()->claim($this->tenant, $number->id, 'both');
            $this->fail('The second claim should have been refused.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            1,
            NumberRental::withoutTenantScope()->where('platform_number_id', $number->id)->count(),
        );

        $this->assertSame(
            0,
            TenantWhatsApp::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count(),
        );
    }

    public function test_the_loser_is_told_on_the_form_not_shown_an_error_page(): void
    {
        $number = PlatformNumber::factory()->rented()->create();

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.whatsapp.rent'), [
                'platform_number_id' => $number->id,
                'bot_type' => 'both',
            ])
            ->assertSessionHasErrors('platform_number_id');
    }

    // ---- one bot per number ----------------------------------------------

    public function test_renting_cannot_claim_a_bot_another_number_already_runs(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create([
            'bot_type' => 'order',
            'status' => 'active',
        ]);

        $number = PlatformNumber::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->rent()->claim($this->tenant, $number->id, 'order');
    }

    public function test_a_second_number_may_take_the_other_bot(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create([
            'bot_type' => 'order',
            'status' => 'active',
        ]);

        $number = PlatformNumber::factory()->create();

        $this->rent()->claim($this->tenant, $number->id, 'support');

        $this->assertSame(
            2,
            TenantWhatsApp::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count(),
        );
    }

    // ---- handing it back -------------------------------------------------

    public function test_releasing_returns_the_number_to_the_pool(): void
    {
        $number = PlatformNumber::factory()->create();
        $rental = $this->rent()->claim($this->tenant, $number->id, 'both');

        $this->actingAs($this->tenant, 'tenant')
            ->delete(route('onboarding.whatsapp.release', $rental->id))
            ->assertRedirect(route('onboarding.step', 'whatsapp'));

        $this->assertSame('available', $number->fresh()->status);
        $this->assertSame('revoked', $rental->fresh()->status);
    }

    public function test_releasing_keeps_the_conversation_history(): void
    {
        // The orders and customers a number carried are the reseller's
        // business, not the number's — handing it back must not take them.
        $number = PlatformNumber::factory()->create();
        $rental = $this->rent()->claim($this->tenant, $number->id, 'both');

        $this->rent()->release($this->tenant, $rental);

        $attached = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        $this->assertSame('inactive', $attached->status);
    }

    public function test_a_released_number_can_be_rented_by_someone_else(): void
    {
        $number = PlatformNumber::factory()->create();
        $rental = $this->rent()->claim($this->tenant, $number->id, 'both');

        $this->rent()->release($this->tenant, $rental);

        $other = Tenant::factory()->create();
        $this->rent()->claim($other, $number->id, 'both');

        $this->assertSame('rented', $number->fresh()->status);
        $this->assertDatabaseHas('tenant_whatsapp', [
            'phone_number_id' => $number->phone_number_id,
            'tenant_id' => $other->id,
        ]);
    }

    public function test_you_cannot_release_someone_elses_rental(): void
    {
        $other = Tenant::factory()->create();
        $number = PlatformNumber::factory()->create();
        $rental = $this->rent()->claim($other, $number->id, 'both');

        $this->actingAs($this->tenant, 'tenant')
            ->delete(route('onboarding.whatsapp.release', $rental->id))
            ->assertNotFound();

        $this->assertSame('active', $rental->fresh()->status);
    }

    // ---- the wizard screen -----------------------------------------------

    public function test_the_wizard_offers_available_numbers_without_their_tokens(): void
    {
        PlatformNumber::factory()->create(['cloud_api_token_enc' => 'secret-token']);
        PlatformNumber::factory()->rented()->create();

        // Everything before this step, so the wizard will render it.
        TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);
        BotService::factory()->for($this->tenant)->create(['status' => 'active']);

        $response = $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'whatsapp'));

        $response->assertOk();

        // Only the free one is offered.
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Onboarding/ConnectWhatsApp')
            ->has('rentable', 1)
        );

        $response->assertDontSee('secret-token');
    }
}
