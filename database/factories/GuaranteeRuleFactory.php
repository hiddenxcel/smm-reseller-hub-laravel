<?php

namespace Database\Factories;

use App\Models\GuaranteeRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuaranteeRule>
 */
class GuaranteeRuleFactory extends Factory
{
    protected $model = GuaranteeRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'panel_id' => null,
            'rule_type' => 'guarantee',
            'keyword' => '30 days',
            'refill_days' => 30,
            'status' => 'active',
        ];
    }

    public function noGuarantee(string $keyword = 'no refill'): static
    {
        return $this->state(fn () => [
            'rule_type' => 'no_guarantee',
            'keyword' => $keyword,
            'refill_days' => null,
        ]);
    }

    public function lifetime(string $keyword = 'lifetime'): static
    {
        return $this->state(fn () => [
            'rule_type' => 'guarantee',
            'keyword' => $keyword,
            'refill_days' => 0,
        ]);
    }
}
