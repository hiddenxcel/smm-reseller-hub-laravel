<?php

namespace App\Services\Billing;

use App\Models\PlatformNumber;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turn a cart into an invoice waiting to be paid.
 *
 * Nothing is granted here — a pending payment buys nothing until the gateway
 * confirms it and ActivatePurchase replays the cart. That split is what keeps
 * an abandoned checkout from handing out a free month.
 */
class Checkout
{
    /**
     * @param  array<int, string>  $services
     *
     * @throws RuntimeException on a cart that cannot be honoured
     */
    public function start(
        Tenant $tenant,
        array $services,
        int $months,
        ?int $platformNumberId,
        string $gateway,
    ): SubscriptionPayment {
        $this->assertGatewayIsAllowed($gateway);

        $number = $this->resolveNumber($platformNumberId);

        $this->assertCartMakesSense($services, $number);

        $quote = Pricing::quote($services, $months, $number);

        return DB::transaction(fn () => SubscriptionPayment::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'gateway' => $gateway,
            'transaction_ref' => $this->reference(),
            'amount' => Pricing::toAmount($quote['total']),
            'currency' => $quote['currency'],
            'months' => $months,
            'items' => $this->itemsFor($quote, $services, $months, $number),
            'status' => 'pending',
        ]));
    }

    /**
     * The cart, recorded so the webhook can replay it.
     *
     * The number carries the bot it was bought for: by the time payment
     * clears, the form that asked is long gone.
     */
    private function itemsFor(array $quote, array $services, int $months, ?PlatformNumber $number): array
    {
        $items = [];

        foreach ($services as $service) {
            $items[] = [
                'type' => 'service',
                'key' => $service,
                'months' => $months,
            ];
        }

        if ($number !== null) {
            $items[] = [
                'type' => 'number',
                'key' => (string) $number->id,
                // Which bot answers on it. Only the two bots are sellable, so
                // a number bought alongside exactly one of them belongs to it.
                'bot_type' => count($services) === 1 && $services[0] === 'support_bot'
                    ? 'support'
                    : 'order',
            ];
        }

        return $items;
    }

    private function resolveNumber(?int $platformNumberId): ?PlatformNumber
    {
        if ($platformNumberId === null) {
            return null;
        }

        $number = PlatformNumber::where('status', 'available')->find($platformNumberId);

        if ($number === null) {
            throw new RuntimeException('That number has just been taken. Please pick another.');
        }

        return $number;
    }

    /**
     * A number with no bot answers nothing, and an empty cart is not a
     * purchase. Both are cheap to catch here and confusing to hit later.
     */
    private function assertCartMakesSense(array $services, ?PlatformNumber $number): void
    {
        if ($services === []) {
            throw new RuntimeException(
                $number !== null
                    ? 'A number needs a bot to answer on it — add one to your order.'
                    : 'Pick at least one service.',
            );
        }
    }

    private function assertGatewayIsAllowed(string $gateway): void
    {
        if (! in_array($gateway, config('billing.gateways', []), true)) {
            throw new RuntimeException('That payment method is not available.');
        }
    }

    /** Unique per payment: it is the webhook's only handle on this row. */
    private function reference(): string
    {
        return 'SUB-'.strtoupper(Str::random(12));
    }
}
