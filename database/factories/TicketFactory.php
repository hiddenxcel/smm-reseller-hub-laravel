<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_identifier' => fake()->numerify('2557########'),
            'category' => 'human',
            'subcategory' => 'order_issue',
            'order_ref' => null,
            'subject' => 'Order not delivered',
            'status' => 'open',
            'priority' => 'normal',
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => 'resolved']);
    }

    /** Raised by the AI assistant rather than a person. */
    public function fromAi(): static
    {
        return $this->state(fn () => ['category' => 'ai']);
    }
}
