<?php

namespace Database\Factories;

use App\Models\BotService;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotService>
 */
class BotServiceFactory extends Factory
{
    protected $model = BotService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'panel_id' => null,
            'provider_service_id' => (string) fake()->numberBetween(100, 9999),
            'platform' => 'Instagram',
            'category' => null,
            'name' => 'Instagram Followers',
            'unit_label' => 'Followers',
            'cost_price' => '1.5000',
            // Panel prices are quoted per 1000 units.
            'my_price' => '2.0000',
            'min_quantity' => 100,
            'max_quantity' => 100000,
            'status' => 'active',
            'sort_order' => 0,
        ];
    }

    public function on(string $platform, ?string $category = null): static
    {
        return $this->state(fn () => [
            'platform' => $platform,
            'category' => $category,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
