<?php

namespace App\Jobs;

use App\Models\BotCustomer;
use App\Models\Tenant;
use App\Services\Customers\CustomerMessaging;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends one broadcast message to one customer.
 *
 * One job per recipient rather than one job for the batch, for three reasons:
 * a single failing number cannot take the whole send down with it; retries
 * apply to just that recipient; and the queue's own rate limiting can space
 * the sends out, which a single long-running job could not.
 *
 * The 24-hour window is re-checked here rather than trusted from when the
 * broadcast was queued — a large send takes minutes, and a customer can fall
 * out of the window while it runs.
 */
class SendCustomerBroadcast implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(
        public int $tenantId,
        public int $customerId,
        public string $text,
    ) {}

    public function handle(): void
    {
        // No tenant session in a queue worker, so the scope cannot apply.
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $customer = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->customerId)
            ->first();

        if ($customer === null || $customer->blocked_at !== null) {
            return;
        }

        $result = CustomerMessaging::for($tenant)->send($customer, $this->text);

        // A refusal here is expected traffic, not a fault — a customer whose
        // window closed mid-send is the normal case. Logged, not retried.
        if ($result->failed) {
            Log::info('Broadcast skipped a customer', [
                'tenant_id' => $this->tenantId,
                'customer_id' => $this->customerId,
                'reason' => $result->message,
            ]);
        }
    }
}
