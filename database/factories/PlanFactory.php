<?php

namespace Database\Factories;

use App\Enums\ServiceKey;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'service_key' => ServiceKey::OrderBot,
            'price_monthly' => 17.00,
            'price_yearly' => 163.20,
            'currency' => 'USD',
            'max_panels' => 5,
            'max_numbers' => 1,
            'status' => 'active',
            'sort_order' => 1,
        ];
    }
}
