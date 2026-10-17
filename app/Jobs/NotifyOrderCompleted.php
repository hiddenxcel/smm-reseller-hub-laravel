<?php

namespace App\Jobs;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotLang;
use App\Services\Bots\BotMessengerFactory;
use App\Services\Bots\BotSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Tells a customer, on WhatsApp, that their order has been delivered.
 *
 * Queued and best-effort, same as NotifyOrderRefunded: the status is already
 * saved, so a number that cannot be messaged (WhatsApp only lets a business
 * start a free-text conversation inside 24 hours of the customer's last
 * message) must not undo anything — the order's own status is the source of
 * truth either way.
 */
class NotifyOrderCompleted implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $orderId,
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
        $locale = BotLang::resolve($customer, $shop['lang'] ?? null);

        $linkLine = $order->link ? "\n🔗 Link: {$order->link}" : '';

        // "1,000 Followers" reads better than a bare "1,000". bot_orders
        // only keeps the provider's own service id (a panel-side string, not
        // bot_services.id), so the unit is looked up by the name saved on
        // the order instead; a renamed or deleted service just loses the
        // word, which is harmless.
        $unit = BotService::withoutTenantScope()
            ->where('tenant_id', $order->tenant_id)
            ->where('name', $order->service_name)
            ->value('unit_label');

        $quantity = $order->quantity !== null ? number_format((int) $order->quantity) : '—';

        $text = BotLang::get(
            $locale,
            'order_completed',
            [
                'number' => $order->provider_order_id ?: $order->id,
                'service' => $order->service_name ?: '—',
                'quantity' => $unit ? "{$quantity} {$unit}" : $quantity,
                'link_line' => $linkLine,
                'business' => $tenant->business_name,
            ],
        );

        try {
            $messengers->forWhatsApp($number, $tenant)->sendText($customer->phone, $text);
        } catch (\Throwable $e) {
            Log::warning('Could not tell a customer their order was completed', [
                'order' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
