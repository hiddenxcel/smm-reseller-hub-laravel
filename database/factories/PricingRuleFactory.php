<?php

namespace Database\Factories;

use App\Models\PricingRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingRule>
 */
class PricingRuleFactory extends Factory
{
    protected $model = PricingRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Standard markup',
            // Null on both means "everything", which is how a catch-all rule
            // is written.
            'platform' => null,
            'panel_id' => null,
            'mode' => PricingRule::PERCENT,
            'amount' => '30.0000',
            'min_profit' => null,
            'max_profit' => null,
            'round_to' => null,
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function forPlatform(string $platform): static
    {
        return $this->state(fn () => ['platform' => $platform]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
