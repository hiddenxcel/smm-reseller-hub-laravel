<?php

namespace Database\Factories;

use App\Models\SupportTicket;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => SupportTicket::makeReference(),
            'tenant_id' => Tenant::factory(),
            'subject' => 'Orders are stuck at pending',
            'category' => 'order-bot',
            'priority' => 'normal',
            'status' => 'open',
            'last_reply_by' => 'tenant',
            'last_reply_at' => now(),
        ];
    }

    /** Answered by us, waiting on the reseller. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'last_reply_by' => 'admin',
            'first_responded_at' => now(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'closed',
            'resolved_at' => now(),
        ]);
    }
}
