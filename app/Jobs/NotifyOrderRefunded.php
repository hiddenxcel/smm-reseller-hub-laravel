<?php

namespace App\Jobs;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotLang;
use App\Services\Bots\BotMessengerFactory;
use App\Services\Bots\BotSettings;
use App\Services\Orders\RefundOrder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Tells a customer, on WhatsApp, that money has gone back to their wallet.
 *
 * Queued and best-effort: the refund has already happened, so a number that
 * cannot be messaged (WhatsApp only lets a business start a free-text
 * conversation inside 24 hours of the customer's last message) must not undo
 * or retry it. The wallet balance is the source of truth either way.
 */
class NotifyOrderRefunded implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $orderId,
        public string $kind,
        public string $amount,
    ) {}

    public function handle(BotMessengerFactory $messengers): void
    {
        $order = BotOrder::withoutTenantScope()->find($this->orderId);
        $customer = $order?->customer_id ? BotCustomer::withoutTenantScope()->find($order->customer_id) : null;
        $tenant = $order ? Tenant::find($order->tenant_id) : null;

        if ($order === null || $customer === null || $tenant === null) {
            return;
        }

        $number = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('bot_type', 'order')
            ->where('status', 'active')
            ->first();

        if ($number === null) {
            return;
        }

        $shop = Arr::get(BotSettings::for((int) $tenant->id, 'order'), 'shop', []);
        $currency = (string) ($shop['currency'] ?? 'USD');
        $locale = BotLang::resolve($customer, $shop['lang'] ?? null);

        $text = BotLang::get(
            $locale,
            $this->kind === RefundOrder::PARTIAL ? 'order_refunded_partial' : 'order_refunded_cancelled',
            [
                'number' => $order->provider_order_id ?: $order->id,
                'service' => $order->service_name ?: '—',
                'amount' => $currency.' '.number_format((float) $this->amount, 2),
                'balance' => $currency.' '.number_format((float) $customer->balance, 2),
            ],
        );

        try {
            $messengers->forWhatsApp($number, $tenant)->sendText($customer->phone, $text);
        } catch (\Throwable $e) {
            Log::warning('Could not tell a customer about their refund', [
                'order' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}