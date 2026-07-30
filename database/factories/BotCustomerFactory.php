<?php

namespace Database\Factories;

use App\Models\BotCustomer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotCustomer>
 */
class BotCustomerFactory extends Factory
{
    protected $model = BotCustomer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'phone' => fake()->unique()->numerify('2557########'),
            'name' => fake()->name(),
            'lang' => 'en',
            'balance' => 0,
            'total_spent' => 0,
            'referral_earnings' => 0,
            'first_deposit_done' => false,
        ];
    }

    public function withBalance(string|float $balance): static
    {
        return $this->state(fn () => ['balance' => $balance]);
    }
}
