<?php

namespace App\Jobs;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotPayment;
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
 * Tells a customer, on WhatsApp, that their payment arrived — and, if they were
 * paying for an order, that it has been placed.
 *
 * Whatever the gateway, and however it was noticed (a webhook, the customer
 * coming back, or us asking the gateway), this is the one message they get, so
 * a customer is never left wondering whether paying worked.
 *
 * Best-effort: the wallet is already credited, so a message that cannot be
 * delivered (WhatsApp lets a business start free text only within 24 hours of
 * the customer's last message) must not undo or retry anything.
 */
class NotifyPaymentCredited implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $paymentId,
        public ?int $orderId = null,
    ) {}

    public function handle(BotMessengerFactory $messengers): void
    {
        $payment = BotPayment::withoutTenantScope()->find($this->paymentId);
        $customer = $payment ? BotCustomer::withoutTenantScope()->find($payment->customer_id) : null;
        $tenant = $payment ? Tenant::find($payment->tenant_id) : null;

        if ($payment === null || $customer === null || $tenant === null) {
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
        $order = $this->orderId ? BotOrder::withoutTenantScope()->find($this->orderId) : null;

        // Three outcomes: money in and the order placed; money in for an order
        // that could not be placed (still short, or it could not be made); or
        // just money in.
        $key = match (true) {
            $order !== null => 'payment_credited_order',
            filled($payment->pending_order) => 'payment_credited_order_waiting',
            default => 'payment_credited',
        };

        $text = BotLang::get($locale, $key, [
            'amount' => $currency.' '.number_format((float) $payment->amount, 2),
            'balance' => $currency.' '.number_format((float) $customer->balance, 2),
            'number' => $order?->id,
            'service' => $order?->service_name ?: '—',
        ]);

        try {
            $messengers->forWhatsApp($number, $tenant)->sendText($customer->phone, $text);
        } catch (\Throwable $e) {
            Log::warning('Could not tell a customer their payment arrived', [
                'payment' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}