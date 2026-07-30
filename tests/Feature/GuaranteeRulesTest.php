<?php

namespace Tests\Feature;

use App\Models\GuaranteeRule;
use App\Models\Tenant;
use App\Services\Guarantee\GuaranteeMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The database-backed half of guarantee matching: loading a tenant's rules.
 * GuaranteeMatcherTest covers the precedence logic itself, without a database.
 */
class GuaranteeRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_rules_for_the_given_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        GuaranteeRule::factory()->for($tenant)->create([
            'keyword' => '60 days',
            'refill_days' => 60,
        ]);

        $verdict = GuaranteeMatcher::forTenant($tenant->id)
            ->evaluate('Instagram Followers | 60 Days');

        $this->assertTrue($verdict->allowed);
        $this->assertSame(60, $verdict->days);
    }

    public function test_one_tenants_rules_do_not_apply_to_another(): void
    {
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        GuaranteeRule::factory()->for($alice)->create([
            'keyword' => '60 days',
            'refill_days' => 60,
        ]);

        // Bob configured no rules, so the same service name must be blocked
        // for him even though Alice would allow it.
        $this->assertTrue(
            GuaranteeMatcher::forTenant($alice->id)->evaluate('Followers | 60 Days')->allowed
        );
        $this->assertFalse(
            GuaranteeMatcher::forTenant($bob->id)->evaluate('Followers | 60 Days')->allowed
        );
    }

    public function test_inactive_rules_are_ignored(): void
    {
        $tenant = Tenant::factory()->create();

        GuaranteeRule::factory()->for($tenant)->create([
            'keyword' => '60 days',
            'refill_days' => 60,
            'status' => 'inactive',
        ]);

        $this->assertFalse(
            GuaranteeMatcher::forTenant($tenant->id)->evaluate('Followers | 60 Days')->allowed
        );
    }

    public function test_a_no_guarantee_rule_from_the_database_blocks(): void
    {
        $tenant = Tenant::factory()->create();

        GuaranteeRule::factory()->for($tenant)->create([
            'keyword' => '30 days',
            'refill_days' => 30,
        ]);
        GuaranteeRule::factory()->for($tenant)->noGuarantee()->create();

        $verdict = GuaranteeMatcher::forTenant($tenant->id)
            ->evaluate('Views | 30 Days | No Refill');

        $this->assertFalse($verdict->allowed);
        $this->assertSame('no refill', $verdict->matchedKeyword);
    }

    public function test_a_lifetime_rule_from_the_database(): void
    {
        $tenant = Tenant::factory()->create();

        GuaranteeRule::factory()->for($tenant)->lifetime()->create();

        $verdict = GuaranteeMatcher::forTenant($tenant->id)
            ->evaluate('Likes | Lifetime Guarantee');

        $this->assertTrue($verdict->allowed);
        $this->assertTrue($verdict->lifetime);
    }

    public function test_it_works_outside_a_tenant_session(): void
    {
        // The bot runs from a webhook with no authenticated session, so
        // forTenant() must not depend on the tenant scope being active.
        $tenant = Tenant::factory()->create();

        GuaranteeRule::factory()->for($tenant)->create([
            'keyword' => '90 days',
            'refill_days' => 90,
        ]);

        $this->assertGuest('tenant');

        $verdict = GuaranteeMatcher::forTenant($tenant->id)->evaluate('Followers | 90 Days');

        $this->assertTrue($verdict->allowed);
        $this->assertSame(90, $verdict->days);
    }
}
