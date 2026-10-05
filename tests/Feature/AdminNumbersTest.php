<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\NumberRental;
use App\Models\PlatformNumber;
use App\Models\Superadmin;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Numbers\RentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The console page that manages the pool of numbers resellers rent.
 *
 * The lines it has to hold: the token is write-only (never sent to the
 * browser), a rented number is never edited out from under its renter, and
 * nothing with rental history is deleted.
 */
class AdminNumbersTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->owner()->create();
        $this->actingAs($this->admin, 'superadmin');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'display_number' => '+255 704 984 690',
            'phone_number_id' => '1137041026167010',
            'token' => 'EAAG-secret-platform-token-9f3a',
            'waba_id' => '1334571474948796',
            'country' => 'Tanzania',
            'country_code' => '255',
            'price' => '12.50',
            ...$overrides,
        ];
    }

    private function rent(PlatformNumber $number, ?Tenant $tenant = null): Tenant
    {
        $tenant ??= Tenant::factory()->create();
        app(RentNumber::class)->claim($tenant, $number->id, 'order');

        return $tenant;
    }

    // ---- the page ----------------------------------------------------------

    public function test_the_page_lists_numbers_and_counts_them(): void
    {
        PlatformNumber::factory()->create();
        PlatformNumber::factory()->create();
        $rented = PlatformNumber::factory()->create();
        PlatformNumber::factory()->create(['status' => 'suspended']);
        $tenant = $this->rent($rented);

        $this->get('/hx-control/numbers')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Numbers/Index')
            ->has('numbers', 4)
            ->where('stats.total', 4)
            ->where('stats.available', 2)
            ->where('stats.rented', 1)
            ->where('stats.suspended', 1)
            ->where('numbers.0.status', 'rented')
            ->where('numbers.0.rentedTo.business', $tenant->business_name));
    }

    public function test_the_token_never_reaches_the_browser(): void
    {
        PlatformNumber::factory()->create(['cloud_api_token_enc' => 'EAAG-super-secret-token-WXYZ']);

        $response = $this->get('/hx-control/numbers')->assertOk();

        $this->assertStringNotContainsString('EAAG-super-secret-token', $response->getContent());
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('numbers.0.tokenSaved', true)
            ->where('numbers.0.tokenHint', 'WXYZ'));
    }

    public function test_the_page_says_whether_the_platform_can_receive_messages(): void
    {
        config(['services.meta.app_secret' => null, 'services.meta.verify_token' => 'verify-me']);

        $this->get('/hx-control/numbers')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('setup.appSecretSet', false)
            ->where('setup.verifyTokenSet', true)
            ->where('setup.webhookUrl', route('webhooks.whatsapp')));

        config(['services.meta.app_secret' => 'a-real-secret']);

        $this->get('/hx-control/numbers')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('setup.appSecretSet', true));
    }

    public function test_support_staff_can_look_but_not_change(): void
    {
        $support = Superadmin::factory()->support()->create();
        $this->actingAs($support, 'superadmin');

        $this->get('/hx-control/numbers')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->post('/hx-control/numbers', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('platform_numbers', 0);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->app['auth']->guard('superadmin')->logout();

        $this->get('/hx-control/numbers')->assertRedirect();
    }

    // ---- adding ------------------------------------------------------------

    public function test_a_number_is_added_normalised_and_available(): void
    {
        $this->post('/hx-control/numbers', $this->payload())->assertSessionHasNoErrors();

        $number = PlatformNumber::firstOrFail();

        $this->assertSame('+255704984690', $number->display_number);
        $this->assertSame('available', $number->status);
        $this->assertSame('12.50', $number->monthly_cost);
        $this->assertSame('EAAG-secret-platform-token-9f3a', $number->cloud_api_token_enc);

        // Encrypted at rest, not just hidden on output.
        $raw = \DB::table('platform_numbers')->value('cloud_api_token_enc');
        $this->assertStringNotContainsString('EAAG-secret', $raw);
    }

    public function test_it_is_priced_in_the_billing_currency(): void
    {
        config(['billing.currency' => 'USD']);

        // A currency in the request is ignored: checkout bills in one.
        $this->post('/hx-control/numbers', $this->payload(['currency' => 'TZS']));

        $this->assertSame('USD', PlatformNumber::firstOrFail()->currency);
    }

    public function test_the_same_number_cannot_be_added_twice_however_it_is_written(): void
    {
        $this->post('/hx-control/numbers', $this->payload());

        $this->post('/hx-control/numbers', $this->payload([
            'display_number' => '255704984690',
            'phone_number_id' => '999',
        ]))->assertSessionHasErrors('display_number');

        $this->post('/hx-control/numbers', $this->payload([
            'display_number' => '+255 700 000 111',
        ]))->assertSessionHasErrors('phone_number_id');

        $this->assertDatabaseCount('platform_numbers', 1);
    }

    public function test_a_token_and_a_price_are_required(): void
    {
        $this->post('/hx-control/numbers', $this->payload(['token' => '', 'price' => '']))
            ->assertSessionHasErrors(['token', 'price']);
    }

    public function test_adding_is_audited_without_the_token(): void
    {
        $this->post('/hx-control/numbers', $this->payload());

        $log = ActivityLog::where('action', 'numbers.create')->firstOrFail();

        $this->assertSame('+255704984690', $log->details['number']);
        $this->assertStringNotContainsString('EAAG', json_encode($log->details));
    }

    // ---- editing -----------------------------------------------------------

    public function test_a_blank_token_keeps_the_saved_one(): void
    {
        $number = PlatformNumber::factory()->create(['cloud_api_token_enc' => 'original-token']);

        $this->patch("/hx-control/numbers/{$number->id}", $this->payload([
            'display_number' => $number->display_number,
            'phone_number_id' => $number->phone_number_id,
            'token' => '',
            'price' => '20',
        ]))->assertSessionHasNoErrors();

        $number->refresh();
        $this->assertSame('original-token', $number->cloud_api_token_enc);
        $this->assertSame('20.00', $number->monthly_cost);
    }

    public function test_replacing_the_token_reaches_the_renters_copy(): void
    {
        $number = PlatformNumber::factory()->create(['cloud_api_token_enc' => 'old-token']);
        $tenant = $this->rent($number);

        $this->patch("/hx-control/numbers/{$number->id}", $this->payload([
            'display_number' => $number->display_number,
            'phone_number_id' => $number->phone_number_id,
            'token' => 'brand-new-token',
        ]))->assertSessionHasNoErrors();

        $copy = TenantWhatsApp::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('brand-new-token', $copy->cloud_api_token_enc);
    }

    public function test_a_rented_number_cannot_be_pointed_at_a_different_number(): void
    {
        $number = PlatformNumber::factory()->create();
        $this->rent($number);
        $original = $number->phone_number_id;

        $this->patch("/hx-control/numbers/{$number->id}", $this->payload([
            'display_number' => $number->display_number,
            'phone_number_id' => '5550001',
        ]))->assertSessionHasErrors('phone_number_id');

        $this->assertSame($original, $number->fresh()->phone_number_id);
    }

    // ---- suspending, restoring, releasing ----------------------------------

    public function test_a_number_can_be_suspended_and_restored(): void
    {
        $number = PlatformNumber::factory()->create();

        $this->post("/hx-control/numbers/{$number->id}/suspend");
        $this->assertSame('suspended', $number->fresh()->status);

        $this->post("/hx-control/numbers/{$number->id}/restore");
        $this->assertSame('available', $number->fresh()->status);
    }

    public function test_a_rented_number_cannot_be_suspended_out_from_under_its_renter(): void
    {
        $number = PlatformNumber::factory()->create();
        $this->rent($number);

        $this->post("/hx-control/numbers/{$number->id}/suspend")->assertSessionHas('error');

        $this->assertSame('rented', $number->fresh()->status);
    }

    public function test_releasing_takes_it_back_and_stops_the_renters_bot(): void
    {
        $number = PlatformNumber::factory()->create();
        $tenant = $this->rent($number);

        $this->post("/hx-control/numbers/{$number->id}/release")->assertSessionHas('success');

        $this->assertSame('available', $number->fresh()->status);
        $this->assertSame('revoked', NumberRental::withoutTenantScope()->firstOrFail()->status);
        $this->assertSame(
            'inactive',
            TenantWhatsApp::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail()->status,
        );

        $log = ActivityLog::where('action', 'numbers.release')->firstOrFail();
        $this->assertSame($tenant->business_name, $log->details['tenant']);
    }

    public function test_a_released_number_can_be_rented_again(): void
    {
        $number = PlatformNumber::factory()->create();
        $this->rent($number);
        $this->post("/hx-control/numbers/{$number->id}/release");

        $second = $this->rent($number->fresh());

        $this->assertSame('rented', $number->fresh()->status);
        $this->assertNotNull($second->id);
    }

    // ---- deleting ----------------------------------------------------------

    public function test_a_number_never_rented_can_be_deleted(): void
    {
        $number = PlatformNumber::factory()->create();

        $this->delete("/hx-control/numbers/{$number->id}")->assertSessionHas('success');

        $this->assertDatabaseCount('platform_numbers', 0);
    }

    public function test_a_number_with_rental_history_is_not_deleted(): void
    {
        $number = PlatformNumber::factory()->create();
        $this->rent($number);
        $this->post("/hx-control/numbers/{$number->id}/release");

        $this->delete("/hx-control/numbers/{$number->id}")->assertSessionHas('error');

        $this->assertDatabaseCount('platform_numbers', 1);
    }

    public function test_a_rented_number_is_not_deleted(): void
    {
        $number = PlatformNumber::factory()->create();
        $this->rent($number);

        $this->delete("/hx-control/numbers/{$number->id}")->assertSessionHas('error');

        $this->assertDatabaseCount('platform_numbers', 1);
    }

    // ---- checking with Meta ------------------------------------------------

    public function test_a_working_number_is_confirmed_by_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'display_phone_number' => '+255 704 984 690',
            'verified_name' => 'Kuza Panel',
            'quality_rating' => 'GREEN',
            'code_verification_status' => 'VERIFIED',
        ])]);

        $this->postJson('/hx-control/numbers/verify', [
            'phone_number_id' => '1137041026167010',
            'token' => 'EAAG-secret-platform-token-9f3a',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('name', 'Kuza Panel')
            ->assertJsonPath('quality', 'GREEN');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/1137041026167010')
            && $request->hasHeader('Authorization', 'Bearer EAAG-secret-platform-token-9f3a'));
    }

    public function test_a_wrong_id_or_dead_token_is_reported_in_meta_s_words(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['error' => ['message' => 'Unsupported get request. Object does not exist.']],
            400,
        )]);

        $this->postJson('/hx-control/numbers/verify', [
            'phone_number_id' => '123',
            'token' => 'bad',
        ])->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Unsupported get request. Object does not exist.');
    }

    public function test_a_saved_number_is_checked_with_its_stored_token_which_never_comes_back(): void
    {
        $number = PlatformNumber::factory()->create(['cloud_api_token_enc' => 'stored-secret-token']);

        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+1 555', 'verified_name' => 'X'])]);

        $response = $this->postJson('/hx-control/numbers/verify', [
            'phone_number_id' => $number->phone_number_id,
            'number_id' => $number->id,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertStringNotContainsString('stored-secret-token', $response->getContent());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer stored-secret-token'));
    }

    public function test_checking_with_no_token_says_so_without_calling_meta(): void
    {
        Http::fake();

        $this->postJson('/hx-control/numbers/verify', ['phone_number_id' => '123'])
            ->assertOk()
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
    }
}
