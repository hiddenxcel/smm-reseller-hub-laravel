<?php

namespace Database\Factories;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => null,
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Pending,
            'starts_at' => null,
            'ends_at' => null,
            'auto_renew' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function sandbox(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Sandbox]);
    }
}
