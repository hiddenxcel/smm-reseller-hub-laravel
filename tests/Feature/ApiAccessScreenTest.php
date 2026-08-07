<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ApiLog;
use App\Models\BotCustomer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The screen where a reseller hands out API access.
 *
 * The rules that matter here are about reach: a reseller issues keys only to
 * their own customers, sees only their own keys and logs, and never gets a
 * stored key back — the plaintext exists for one render and no longer.
 */
class ApiAccessScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    public function test_the_screen_is_closed_to_visitors(): void
    {
        $this->get('/api-access')->assertRedirect('/login');
    }

    public function test_a_reseller_sees_their_keys(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        [$key] = ApiKey::issue($customer, 'Their live site');

        $this->actingAs($this->tenant)
            ->get('/api-access')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Api/Index')
                ->where('tab', 'keys')
                ->has('keys', 1)
                ->where('keys.0.label', 'Their live site')
                ->where('keys.0.prefix', $key->key_prefix));
    }

    public function test_a_reseller_never_sees_another_resellers_keys(): void
    {
        $other = BotCustomer::factory()->create();
        ApiKey::issue($other);

        $this->actingAs($this->tenant)
            ->get('/api-access')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('keys', 0));
    }

    /**
     * The whole point of storing a hash: there is no route back to the key,
     * and the screen must not be one.
     */
    public function test_the_stored_key_is_never_sent_to_the_browser(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        [$key] = ApiKey::issue($customer);

        $response = $this->actingAs($this->tenant)->get('/api-access');

        $response->assertOk();
        $response->assertDontSee($key->key_hash);
    }

    public function test_issuing_a_key_returns_the_plaintext_once(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->tenant)->post('/api-access/keys', [
            'customer_id' => $customer->id,
            'label' => 'Live',
        ]);

        $response->assertRedirect()->assertSessionHas('newApiKey');

        $plaintext = session('newApiKey');
        $this->assertStringStartsWith('hxk_', $plaintext);

        // And it is the key that was actually stored.
        $this->assertDatabaseHas('api_keys', [
            'customer_id' => $customer->id,
            'key_hash' => ApiKey::hash($plaintext),
            'label' => 'Live',
        ]);
    }

    /**
     * The customer id arrives from the request, so it is the one field that
     * must be proved to belong to this reseller.
     */
    public function test_a_key_cannot_be_issued_to_another_resellers_customer(): void
    {
        $stranger = BotCustomer::factory()->create();

        $this->actingAs($this->tenant)
            ->post('/api-access/keys', ['customer_id' => $stranger->id])
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(0, ApiKey::withoutTenantScope()->count());
    }

    public function test_revoking_a_key_stops_it_working(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        [$key, $plaintext] = ApiKey::issue($customer);

        $this->actingAs($this->tenant)
            ->delete("/api-access/keys/{$key->id}")
            ->assertRedirect();

        $this->assertSame(ApiKey::REVOKED, $key->fresh()->status);

        // The API refuses it from the next request onwards.
        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertJson(['error' => 'Invalid API key']);
    }

    /**
     * Revoked, not deleted: the logs point at the key, and "what did this key
     * do before I turned it off" has to stay answerable.
     */
    public function test_revoking_keeps_the_row_and_its_logs(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        [$key] = ApiKey::issue($customer);

        ApiLog::create([
            'tenant_id' => $this->tenant->id,
            'api_key_id' => $key->id,
            'action' => 'add',
            'ok' => true,
        ]);

        $this->actingAs($this->tenant)->delete("/api-access/keys/{$key->id}");

        $this->assertDatabaseHas('api_keys', ['id' => $key->id]);
        $this->assertDatabaseHas('api_logs', ['api_key_id' => $key->id]);
    }

    public function test_a_reseller_cannot_revoke_another_resellers_key(): void
    {
        $stranger = BotCustomer::factory()->create();
        [$key] = ApiKey::issue($stranger);

        $this->actingAs($this->tenant)
            ->delete("/api-access/keys/{$key->id}")
            ->assertNotFound();

        $this->assertSame(ApiKey::ACTIVE, $key->fresh()->status);
    }

    public function test_limits_can_be_changed(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        [$key] = ApiKey::issue($customer);

        $this->actingAs($this->tenant)->patch("/api-access/keys/{$key->id}", [
            'label' => 'Staging',
            'rate_limit' => 30,
            'ip_allowlist' => ['203.0.113.7'],
        ])->assertRedirect();

        $key->refresh();
        $this->assertSame('Staging', $key->label);
        $this->assertSame(30, $key->rate_limit);
        $this->assertSame(['203.0.113.7'], $key->ip_allowlist);
    }

    /**
     * An emptied allowlist is stored as null — "no restriction" — rather than
     * as [], which reads as "nobody" and would lock a customer out by saving
     * an unfinished form.
     */
    public function test_an_emptied_allowlist_is_stored_as_no_restriction(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        [$key] = ApiKey::issue($customer);
        $key->update(['ip_allowlist' => ['203.0.113.7']]);

        $this->actingAs($this->tenant)->patch("/api-access/keys/{$key->id}", [
            'ip_allowlist' => [],
        ]);

        $this->assertNull($key->fresh()->ip_allowlist);
    }

    public function test_a_bad_ip_is_refused(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        [$key] = ApiKey::issue($customer);

        $this->actingAs($this->tenant)
            ->patch("/api-access/keys/{$key->id}", ['ip_allowlist' => ['not-an-ip']])
            ->assertSessionHasErrors('ip_allowlist.0');
    }

    public function test_the_docs_tab_lists_every_action(): void
    {
        $this->actingAs($this->tenant)
            ->get('/api-access/docs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'docs')
                ->has('actions')
                ->where('endpoint', url('/api/v2')));
    }

    public function test_the_logs_tab_shows_this_resellers_requests(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        [$key] = ApiKey::issue($customer);

        ApiLog::create([
            'tenant_id' => $this->tenant->id,
            'api_key_id' => $key->id,
            'action' => 'add',
            'ok' => false,
            'error' => 'Not enough balance',
        ]);

        // Another reseller's request must not appear.
        ApiLog::withoutTenantScope()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'action' => 'add',
            'ok' => true,
        ]);

        $this->actingAs($this->tenant)
            ->get('/api-access/logs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'logs')
                ->has('logs.data', 1)
                ->where('logs.data.0.error', 'Not enough balance'));
    }

    public function test_the_logs_can_be_narrowed_to_failures(): void
    {
        $customer = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        [$key] = ApiKey::issue($customer);

        foreach ([true, false] as $ok) {
            ApiLog::create([
                'tenant_id' => $this->tenant->id,
                'api_key_id' => $key->id,
                'action' => 'add',
                'ok' => $ok,
            ]);
        }

        $this->actingAs($this->tenant)
            ->get('/api-access/logs?failed=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.ok', false));
    }

    /**
     * Only the tab being viewed is built — the logs query has no business
     * running because someone opened the documentation.
     */
    public function test_a_tab_does_not_build_the_other_tabs_payloads(): void
    {
        $this->actingAs($this->tenant)
            ->get('/api-access/docs')
            ->assertInertia(fn ($page) => $page->missing('logs')->missing('customers'));
    }

    public function test_an_unknown_tab_is_not_a_route(): void
    {
        $this->actingAs($this->tenant)->get('/api-access/nonsense')->assertNotFound();
    }
}
