<?php

namespace App\Jobs;

use App\Models\BotOrder;
use App\Models\TenantPanel;
use App\Services\Panel\SmmProviderClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Forwards a placed order to the reseller's panel.
 *
 * This is queued rather than inline because the old code made this 30-second
 * HTTP call inside the WhatsApp webhook request. Meta retries a webhook that
 * does not answer quickly, and each retry re-ran the flow — a duplicate-order
 * generator. The order is recorded first (see PlaceOrder), then submitted here.
 */
class SubmitOrderToPanel implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Back off a little between attempts; panels are often briefly flaky. */
    public array $backoff = [10, 60];

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        // No tenant session in a queue worker, so the scope cannot apply.
        $order = BotOrder::withoutTenantScope()->find($this->orderId);

        if ($order === null || $order->provider_order_id !== null) {
            return; // gone, or already submitted on an earlier attempt
        }

        if ($order->panel_id === null) {
            return; // catalogue-only service, nothing to forward
        }

        $panel = TenantPanel::withoutTenantScope()
            ->where('tenant_id', $order->tenant_id)
            ->whereKey($order->panel_id)
            ->first();

        if ($panel === null) {
            $order->update(['order_error' => 'Panel no longer connected', 'status' => 'Failed']);

            return;
        }

        $result = SmmProviderClient::forPanel($panel)->addOrder(
            (string) $order->service_id,
            (string) $order->link,
            (int) $order->quantity,
        );

        if ($result->failed) {
            // Let the job retry; record the reason so the reseller can see it
            // even while attempts remain.
            $order->update(['order_error' => $result->message]);

            throw new \RuntimeException("Panel rejected order {$order->id}: {$result->message}");
        }

        $order->update([
            'provider_order_id' => $result->get('order_id'),
            'status' => 'processing',
            'order_error' => null,
        ]);
    }

    /**
     * Every attempt has been refused.
     *
     * The order is marked failed so the reseller sees it and can resend it —
     * the customer is not told: to them it is still pending. Nothing is
     * refunded, because the provider never had it.
     */
    public function failed(\Throwable $exception): void
    {
        $order = BotOrder::withoutTenantScope()->find($this->orderId);

        if ($order === null || $order->provider_order_id !== null) {
            return;
        }

        $order->update([
            'status' => 'Failed',
            'order_error' => $order->order_error ?: mb_substr($exception->getMessage(), 0, 250),
        ]);
    }
}
