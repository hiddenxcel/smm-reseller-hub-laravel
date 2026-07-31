<?php

namespace Database\Factories;

use App\Models\PlatformNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformNumber>
 */
class PlatformNumberFactory extends Factory
{
    protected $model = PlatformNumber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_number' => fake()->unique()->numerify('2557########'),
            'phone_number_id' => fake()->unique()->numerify('1############'),
            'cloud_api_token_enc' => 'platform-token',
            'waba_id' => fake()->numerify('1##########'),
            'country' => 'Tanzania',
            'country_code' => '255',
            'currency' => 'USD',
            'monthly_cost' => '15.00',
            'status' => 'available',
        ];
    }

    public function rented(): static
    {
        return $this->state(fn () => ['status' => 'rented']);
    }
}
