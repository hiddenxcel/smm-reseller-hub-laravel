<?php

namespace Database\Factories;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotOrder>
 */
class BotOrderFactory extends Factory
{
    protected $model = BotOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // One tenant instance shared by the order and its customer — a
        // customer belonging to a different tenant than its own order is a
        // state the app can never produce, so fixtures should not either.
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,
            'panel_id' => null,
            'provider_order_id' => null,
            'customer_phone' => fake()->numerify('2557########'),
            'customer_id' => BotCustomer::factory()->for($tenant),
            'service_id' => '1234',
            'service_name' => 'Instagram Followers',
            'link' => 'https://instagram.com/example',
            'quantity' => 500,
            'amount' => '2.50',
            'payment_status' => 'paid',
            'paid_from' => 'wallet',
            'status' => 'pending',
        ];
    }

    /** Already forwarded to the panel. */
    public function submitted(string $providerOrderId = '48220'): static
    {
        return $this->state(fn () => [
            'provider_order_id' => $providerOrderId,
            'status' => 'processing',
        ]);
    }
}
