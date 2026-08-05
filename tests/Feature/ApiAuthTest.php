<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ApiLog;
use App\Models\BotCustomer;
use App\Models\BotService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who gets in, and what a refusal looks like.
 *
 * The API key is the only thing a request carries — no session, no CSRF — so
 * these are the whole boundary. Refusals are logged as carefully as successes
 * because a caller who cannot get in is the caller whose reseller will be
 * asked what went wrong.
 */
class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    private function issueKey(array $customerAttributes = []): array
    {
        $customer = BotCustomer::factory()->create($customerAttributes);

        return ApiKey::issue($customer);
    }

    public function test_a_valid_key_reaches_the_api(): void
    {
        [, $plaintext] = $this->issueKey();

        $response = $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance']);

        $response->assertOk()->assertJsonStructure(['balance', 'currency']);
    }

    public function test_a_missing_key_is_refused(): void
    {
        $this->postJson('/api/v2', ['action' => 'balance'])
            ->assertOk()
            ->assertJson(['error' => 'Invalid API key']);
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->postJson('/api/v2', ['key' => 'hxk_nothing', 'action' => 'balance'])
            ->assertOk()
            ->assertJson(['error' => 'Invalid API key']);
    }

    /**
     * A revoked key is refused in the same words as an unknown one: the
     * difference is only useful to someone probing.
     */
    public function test_a_revoked_key_is_refused_indistinguishably(): void
    {
        [$key, $plaintext] = $this->issueKey();
        $key->update(['status' => ApiKey::REVOKED]);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertOk()
            ->assertJson(['error' => 'Invalid API key']);
    }

    public function test_a_refusal_is_answered_with_200_not_401(): void
    {
        // The SMM convention puts failures in the body. Clients read `error`
        // and ignore the status, so a "correct" 401 breaks them.
        $this->postJson('/api/v2', ['key' => 'nope', 'action' => 'balance'])
            ->assertStatus(200);
    }

    public function test_the_endpoint_needs_no_csrf_token(): void
    {
        [, $plaintext] = $this->issueKey();

        $this->post('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertOk();
    }

    public function test_a_key_only_reaches_its_own_tenants_data(): void
    {
        [, $plaintext] = $this->issueKey();

        // Another reseller's catalogue must not appear.
        $other = Tenant::factory()->create();
        BotService::factory()->count(3)->create(['tenant_id' => $other->id]);

        $response = $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'services']);

        $response->assertOk()->assertJsonCount(0);
    }

    public function test_a_suspended_reseller_stops_taking_orders(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'suspended']);
        [, $plaintext] = $this->issueKey(['tenant_id' => $tenant->id]);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertOk()
            ->assertJson(['error' => 'This shop is not accepting orders']);
    }

    public function test_a_blocked_customer_is_refused_as_in_the_bot(): void
    {
        [, $plaintext] = $this->issueKey(['blocked_at' => now()]);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertOk()
            ->assertJson(['error' => 'This account cannot place orders']);
    }

    public function test_an_ip_outside_the_allowlist_is_refused(): void
    {
        $customer = BotCustomer::factory()->create();
        [$key, $plaintext] = ApiKey::issue($customer);
        $key->update(['ip_allowlist' => ['203.0.113.7']]);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'], [
            'REMOTE_ADDR' => '198.51.100.4',
        ])->assertOk()->assertJson([
            'error' => 'This IP address is not allowed to use this key',
        ]);
    }

    public function test_an_ip_on_the_allowlist_is_admitted(): void
    {
        $customer = BotCustomer::factory()->create();
        [$key, $plaintext] = ApiKey::issue($customer);
        $key->update(['ip_allowlist' => ['127.0.0.1']]);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertOk()
            ->assertJsonMissingPath('error');
    }

    /**
     * An empty allowlist means the reseller edited it down to nothing, not
     * that nobody may call. Locking someone out of their own integration over
     * an empty array is the worse failure.
     */
    public function test_an_empty_allowlist_does_not_lock_everyone_out(): void
    {
        $customer = BotCustomer::factory()->create();
        [$key, $plaintext] = ApiKey::issue($customer);
        $key->update(['ip_allowlist' => []]);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertOk()
            ->assertJsonMissingPath('error');
    }

    public function test_a_refused_request_is_still_logged(): void
    {
        $this->postJson('/api/v2', ['key' => 'hxk_wrong', 'action' => 'balance']);

        $log = ApiLog::withoutTenantScope()->first();

        $this->assertNotNull($log, 'a refused request must leave a log row');
        $this->assertFalse($log->ok);
        $this->assertSame('Invalid API key', $log->error);
        // No key resolved, so there is no tenant to attribute it to.
        $this->assertNull($log->tenant_id);
    }

    public function test_a_successful_request_is_logged_against_its_key(): void
    {
        [$key, $plaintext] = $this->issueKey();

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance']);

        $log = ApiLog::withoutTenantScope()->first();

        $this->assertTrue($log->ok);
        $this->assertSame('balance', $log->action);
        $this->assertSame($key->id, $log->api_key_id);
        $this->assertSame($key->tenant_id, $log->tenant_id);
    }

    public function test_using_a_key_records_when_it_was_last_used(): void
    {
        [$key, $plaintext] = $this->issueKey();

        $this->assertNull($key->last_used_at);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance']);

        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        [, $plaintext] = $this->issueKey();

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'drop_everything'])
            ->assertOk()
            ->assertJson(['error' => 'Invalid action']);
    }

    public function test_the_stored_key_is_a_hash_not_the_key_itself(): void
    {
        [$key, $plaintext] = $this->issueKey();

        $this->assertNotSame($plaintext, $key->key_hash);
        $this->assertSame(hash('sha256', $plaintext), $key->key_hash);
        // The prefix is for identifying a key on screen, and is not enough to
        // authenticate with.
        $this->assertSame(mb_substr($plaintext, 0, 12), $key->key_prefix);
    }
}
