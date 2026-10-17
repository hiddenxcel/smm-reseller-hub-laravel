<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Services\Billing\ActivatePurchase;
use App\Services\Billing\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whose name the bot signs with: ours until the Custom brand boost is paid
 * for, then the reseller's own — and ours again the day it lapses.
 */
class CustomBrandTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::create([
            'business_name' => 'Zed Growth',
            'email' => 'zed@example.test',
            'password_hash' => 'x',
            'status' => 'active',
        ]);
    }

    private function plan(string $status = 'active'): Plan
    {
        return Plan::updateOrCreate(['code' => 'custom_brand'], [
            'service_key' => ServiceKey::CustomBrand,
            'name' => 'Your own brand name',
            'price_monthly' => '2.00',
            'price_yearly' => '19.20',
            'currency' => 'USD',
            'status' => $status,
        ]);
    }

    private function subscribe(Tenant $tenant, ?string $endsAt): void
    {
        Subscription::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'service_key' => ServiceKey::CustomBrand,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => $endsAt,
        ]);
    }

    public function test_unpaid_tenant_is_signed_with_our_name(): void
    {
        $this->assertSame('Auto Hub', $this->tenant()->brandName());
    }

    public function test_paid_tenant_is_signed_with_their_own_name(): void
    {
        $this->plan();
        $tenant = $this->tenant();
        $this->subscribe($tenant, now()->addMonth()->toDateTimeString());

        $this->assertSame('Zed Growth', $tenant->brandName());
    }

    public function test_lapsed_boost_goes_back_to_our_name(): void
    {
        $tenant = $this->tenant();
        $this->subscribe($tenant, now()->subDay()->toDateTimeString());

        $this->assertSame('Auto Hub', $tenant->brandName());
    }

    public function test_switching_the_feature_off_takes_it_from_everyone(): void
    {
        $this->plan('inactive');
        $tenant = $this->tenant();
        $this->subscribe($tenant, null);

        $this->assertSame('Auto Hub', $tenant->brandName());
    }

    public function test_it_costs_the_same_whatever_the_term_and_never_expires(): void
    {
        $this->plan();
        $tenant = $this->tenant();

        $this->assertSame(200, Pricing::serviceTotal('custom_brand', 1));
        $this->assertSame(200, Pricing::serviceTotal('custom_brand', 12));

        $payment = SubscriptionPayment::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'gateway' => 'snippe',
            'transaction_ref' => 'SUB-ONETIME0001',
            'amount' => '2.00',
            'credit_applied' => '0.00',
            'currency' => 'USD',
            'months' => 6,
            'items' => [['type' => 'service', 'key' => 'custom_brand', 'months' => 6]],
            'status' => 'pending',
        ]);

        app(ActivatePurchase::class)->apply($payment);

        $subscription = Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $this->assertNull($subscription->ends_at);
        $this->assertSame('Zed Growth', $tenant->brandName());
    }
}
