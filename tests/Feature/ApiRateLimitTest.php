<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\BotCustomer;
use App\Services\Api\ApiRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The request budget.
 *
 * It exists to stop a runaway loop, not to ration: the caller is a shop's
 * checkout, and a limit that bites during a normal sales burst costs the
 * reseller money. These tests hold it to that — refuse the loop, admit the
 * burst, and never quietly stop counting.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function keyLimitedTo(int $perMinute): array
    {
        $customer = BotCustomer::factory()->create();
        [$key, $plaintext] = ApiKey::issue($customer);
        $key->update(['rate_limit' => $perMinute]);

        return [$key->fresh(), $plaintext];
    }

    public function test_requests_within_the_budget_are_admitted(): void
    {
        [, $plaintext] = $this->keyLimitedTo(3);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
                ->assertOk()
                ->assertJsonMissingPath('error');
        }
    }

    public function test_the_request_after_the_budget_is_refused(): void
    {
        [, $plaintext] = $this->keyLimitedTo(2);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance']);
        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance']);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertOk()
            ->assertJson(['error' => 'Too many requests']);
    }

    public function test_a_new_minute_starts_a_fresh_budget(): void
    {
        [$key, $plaintext] = $this->keyLimitedTo(1);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertJsonMissingPath('error');

        // Age the window rather than sleeping a minute.
        DB::table('rate_limits')
            ->where('identifier', 'key:'.$key->id)
            ->update(['window_start' => now()->subMinutes(2)]);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance'])
            ->assertOk()
            ->assertJsonMissingPath('error');
    }

    public function test_one_keys_budget_does_not_spend_anothers(): void
    {
        [, $first] = $this->keyLimitedTo(1);
        [, $second] = $this->keyLimitedTo(1);

        $this->postJson('/api/v2', ['key' => $first, 'action' => 'balance']);

        // The first key is now spent; the second must be untouched.
        $this->postJson('/api/v2', ['key' => $first, 'action' => 'balance'])
            ->assertJson(['error' => 'Too many requests']);

        $this->postJson('/api/v2', ['key' => $second, 'action' => 'balance'])
            ->assertJsonMissingPath('error');
    }

    /**
     * A key that names no limit takes the platform default, so raising the
     * default later lifts every key that never asked for a number.
     */
    public function test_a_key_without_its_own_limit_takes_the_default(): void
    {
        $customer = BotCustomer::factory()->create();
        [$key] = ApiKey::issue($customer);

        $this->assertNull($key->rate_limit);
        $this->assertSame(ApiKey::DEFAULT_RATE_LIMIT, $key->limitPerMinute());
    }

    public function test_a_refused_request_is_logged_so_the_reseller_can_see_it(): void
    {
        [, $plaintext] = $this->keyLimitedTo(1);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance']);
        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance']);

        $this->assertDatabaseHas('api_logs', [
            'ok' => false,
            'error' => 'Too many requests',
        ]);
    }

    /**
     * The limiter counts in the database rather than the cache on purpose: the
     * cache store is an array in tests and could be a dead Redis in
     * production, and a limiter that silently stops limiting is worse than one
     * that costs a row.
     */
    public function test_the_counter_is_persisted_not_held_in_a_cache(): void
    {
        [$key, $plaintext] = $this->keyLimitedTo(5);

        $this->postJson('/api/v2', ['key' => $plaintext, 'action' => 'balance']);

        $this->assertDatabaseHas('rate_limits', [
            'identifier' => 'key:'.$key->id,
            'action' => 'api',
            'attempts' => 1,
        ]);
    }

    public function test_the_limiter_refuses_at_the_boundary_not_after_it(): void
    {
        $customer = BotCustomer::factory()->create();
        [$key] = ApiKey::issue($customer);
        $key->update(['rate_limit' => 2]);

        $limiter = app(ApiRateLimiter::class);

        $this->assertTrue($limiter->attempt($key->fresh()));
        $this->assertTrue($limiter->attempt($key->fresh()));
        $this->assertFalse($limiter->attempt($key->fresh()));
    }
}
