<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantPaymentGateway>
 */
class TenantPaymentGatewayFactory extends Factory
{
    protected $model = TenantPaymentGateway::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'gateway' => 'snippe',
            'api_key_enc' => 'test-api-key',
            'webhook_secret_enc' => 'test-webhook-secret',
            'status' => 'active',
        ];
    }
}
