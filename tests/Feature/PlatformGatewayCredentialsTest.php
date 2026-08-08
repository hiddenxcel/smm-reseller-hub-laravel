<?php

namespace Tests\Feature;

use App\Models\PlatformGatewayCredential;
use App\Models\Superadmin;
use App\Services\Billing\PlatformGateways;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The platform's own merchant keys — how resellers pay us.
 *
 * These decide where subscription money lands, so the rules around them are
 * worth pinning: an environment value must keep winning, a stored key must
 * stay encrypted, and only an owner may set one.
 */
class PlatformGatewayCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite inherits whatever .env holds; these tests are about the
        // database path, so the environment side starts empty.
        config([
            'services.billing.cryptomus.api_key' => null,
            'services.billing.cryptomus.merchant_id' => null,
        ]);

        PlatformGatewayCredential::forget();
    }

    public function test_a_gateway_with_no_keys_is_not_offered(): void
    {
        $this->assertFalse(PlatformGateways::isConfigured('cryptomus'));
        $this->assertSame('none', PlatformGateways::source('cryptomus'));
    }

    public function test_keys_are_stored_before_they_are_trusted(): void
    {
        // Saved but not switched on: an owner should be able to paste a key
        // and check it before resellers are shown the option.
        PlatformGatewayCredential::create([
            'gateway' => 'cryptomus',
            'api_key_enc' => 'key-ABCD',
            'extra_enc' => 'merchant-1',
            'enabled' => false,
        ]);
        PlatformGatewayCredential::forget();

        $this->assertFalse(PlatformGateways::isConfigured('cryptomus'));
        $this->assertSame('stored-disabled', PlatformGateways::source('cryptomus'));
    }

    public function test_an_enabled_gateway_becomes_usable(): void
    {
        PlatformGatewayCredential::create([
            'gateway' => 'cryptomus',
            'api_key_enc' => 'key-ABCD',
            'extra_enc' => 'merchant-1',
            'enabled' => true,
        ]);
        PlatformGatewayCredential::forget();

        $this->assertTrue(PlatformGateways::isConfigured('cryptomus'));
        $this->assertSame('database', PlatformGateways::source('cryptomus'));
        $this->assertNotNull(PlatformGateways::make('cryptomus'));
    }

    public function test_the_environment_wins_over_the_database(): void
    {
        // An operator who keeps keys in .env — the safer arrangement — must
        // not have them overridden by anything typed into the console.
        config(['services.billing.cryptomus.api_key' => 'from-env']);

        PlatformGatewayCredential::create([
            'gateway' => 'cryptomus',
            'api_key_enc' => 'from-database',
            'extra_enc' => 'merchant-1',
            'enabled' => true,
        ]);
        PlatformGatewayCredential::forget();

        $this->assertSame('env', PlatformGateways::source('cryptomus'));
        $this->assertSame('from-env', PlatformGateways::keys('cryptomus')['api_key']);
    }

    public function test_a_stored_key_is_encrypted_at_rest(): void
    {
        PlatformGatewayCredential::create([
            'gateway' => 'cryptomus',
            'api_key_enc' => 'secret-value-here',
            'enabled' => true,
        ]);

        $raw = DB::table('platform_gateway_credentials')
            ->where('gateway', 'cryptomus')
            ->value('api_key_enc');

        $this->assertStringNotContainsString('secret-value-here', $raw);
    }

    public function test_only_the_hint_is_ever_exposed(): void
    {
        PlatformGatewayCredential::create([
            'gateway' => 'cryptomus',
            'api_key_enc' => 'abcdefghijkl',
            'extra_enc' => 'merchant-1',
            'enabled' => true,
        ]);
        PlatformGatewayCredential::forget();

        $hint = PlatformGateways::hint('cryptomus');

        $this->assertSame('…ijkl', $hint);
        $this->assertStringNotContainsString('abcdefgh', (string) $hint);
    }

    public function test_a_support_admin_cannot_set_a_gateway(): void
    {
        // These keys decide where money lands, so an admin who could set them
        // could redirect it to an account of their own.
        $support = Superadmin::factory()->create(['role' => 'support']);

        $this->actingAs($support, 'superadmin')
            ->post(route('admin.settings.gateway.save', 'cryptomus'), [
                'api_key' => 'theirs',
                'enabled' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('platform_gateway_credentials', 0);
    }

    public function test_an_owner_can_set_one(): void
    {
        $owner = Superadmin::factory()->create(['role' => 'owner']);

        $this->actingAs($owner, 'superadmin')
            ->post(route('admin.settings.gateway.save', 'cryptomus'), [
                'api_key' => 'key-ABCD',
                'extra' => 'merchant-1',
                'enabled' => true,
            ])
            ->assertRedirect();

        PlatformGatewayCredential::forget();

        $this->assertTrue(PlatformGateways::isConfigured('cryptomus'));
    }

    public function test_a_blank_field_keeps_what_is_stored(): void
    {
        // Values are never sent back to the browser, so an owner editing one
        // field cannot re-type the others — a blank must mean "leave it".
        $owner = Superadmin::factory()->create(['role' => 'owner']);

        PlatformGatewayCredential::create([
            'gateway' => 'cryptomus',
            'api_key_enc' => 'original-key',
            'extra_enc' => 'merchant-1',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'superadmin')
            ->post(route('admin.settings.gateway.save', 'cryptomus'), [
                'api_key' => '',
                'extra' => 'merchant-2',
                'enabled' => true,
            ]);

        PlatformGatewayCredential::forget();

        $this->assertSame('original-key', PlatformGateways::keys('cryptomus')['api_key']);
        $this->assertSame('merchant-2', PlatformGateways::keys('cryptomus')['merchant_id']);
    }

    public function test_an_unknown_gateway_is_refused(): void
    {
        $owner = Superadmin::factory()->create(['role' => 'owner']);

        $this->actingAs($owner, 'superadmin')
            ->post(route('admin.settings.gateway.save', 'not-a-gateway'), [
                'api_key' => 'x',
                'enabled' => true,
            ])
            ->assertNotFound();
    }
}
