<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantPanel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantPanel>
 */
class TenantPanelFactory extends Factory
{
    protected $model = TenantPanel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->company().' Panel',
            'panel_type' => 'custom',
            'api_url' => 'https://panel.example.com/api/v2',
            'api_key_enc' => 'test-api-key',
            'api_version' => 'v2',
            'auth_method' => 'param',
            'status' => 'active',
        ];
    }

    public function headerAuth(): static
    {
        return $this->state(fn () => ['auth_method' => 'header']);
    }
}
