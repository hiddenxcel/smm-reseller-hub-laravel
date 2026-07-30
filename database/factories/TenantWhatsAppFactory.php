<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantWhatsApp>
 */
class TenantWhatsAppFactory extends Factory
{
    protected $model = TenantWhatsApp::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'source' => 'own',
            'cloud_api_token_enc' => 'test-cloud-token',
            'phone_number_id' => (string) fake()->unique()->numerify('##############'),
            'display_number' => fake()->numerify('2557########'),
            'status' => 'active',
            'bot_type' => 'both',
        ];
    }

    public function orderOnly(): static
    {
        return $this->state(fn () => ['bot_type' => 'order']);
    }

    public function supportOnly(): static
    {
        return $this->state(fn () => ['bot_type' => 'support']);
    }

    /** A number with no usable token cannot reply at all. */
    public function withoutToken(): static
    {
        return $this->state(fn () => ['cloud_api_token_enc' => null]);
    }
}
