<?php

namespace Database\Factories;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotPayment>
 */
class BotPaymentFactory extends Factory
{
    protected $model = BotPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The customer must belong to the same tenant as the payment.
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,
            'type' => 'wallet_topup',
            'customer_id' => BotCustomer::factory()->for($tenant),
            'order_id' => null,
            'gateway' => 'snippe',
            'transaction_ref' => 'SMMTOP'.fake()->unique()->numerify('#####'),
            'amount' => '5.00',
            'status' => 'pending',
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => ['status' => 'success']);
    }
}
