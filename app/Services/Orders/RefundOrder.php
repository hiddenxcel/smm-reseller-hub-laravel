<?php

namespace App\Services\Orders;

use App\Jobs\NotifyOrderRefunded;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Services\Bots\BotSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give a customer their money back when the provider did not deliver.
 *
 * Only for an order that actually reached the provider. One that failed before
 * getting there stays failed and is the reseller's to resend; it is never
 * refunded here, because nothing has been tried and lost yet.
 *
 * What comes back depends on what the provider says:
 *   - cancelled (or refunded, or errored): everything the customer paid;
 *   - partial: the share that was not delivered, worked out from the units the
 *     provider reports as remaining.
 *
 * Refunding is "bring the total refunded up to what is owed", so running it
 * twice — the sync overlapping itself, a cancel and a sync racing — returns
 * nothing the second time.
 */
class RefundOrder
{
    public const CANCELLED = 'cancelled';

    public const PARTIAL = 'partial';

    /** Whether this shop has switched automatic refunds on. On unless turned off. */
    public static function enabledFor(int $tenantId): bool
    {
        return (bool) Arr::get(BotSettings::for($tenantId, 'order'), 'shop.auto_refund', true);
    }

    /**
     * What this status makes the customer owed, as [kind, amount] — or null
     * when it is not a status that refunds, or the amount cannot be worked out.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function owed(BotOrder $order): ?array
    {
        $status = mb_strtolower((string) $order->status);
        $paid = (string) $order->amount;

        if (bccomp($paid, '0', 2) <= 0) {
            return null;
        }

        // Partial first: "partially refunded" contains "refund" too.
        if (str_contains($status, 'partial')) {
            $quantity = (int) $order->quantity;
            $remains = $order->remains;

            // Without both numbers the share cannot be computed, and guessing
            // would move real money on a guess.
            if ($quantity <= 0 || $remains === null) {
                return null;
            }

            $remains = min((int) $remains, $quantity);

            if ($remains <= 0) {
                return null;
            }

            $share = bcdiv(bcmul($paid, (string) $remains, 6), (string) $quantity, 6);

            return [self::PARTIAL, number_format(round((float) $share, 2), 2, '.', '')];
        }

        foreach (['cancel', 'refund', 'fail', 'error'] as $word) {
            if (str_contains($status, $word)) {
                return [self::CANCELLED, number_format((float) $paid, 2, '.', '')];
            }
        }

        return null;
    }

    /**
     * Refund what is owed, if anything. Returns the amount sent back now, or
     * null when nothing was (switched off, not eligible, or already done).
     */
    public function handle(BotOrder $order): ?string
    {
        if (! self::enabledFor((int) $order->tenant_id)) {
            return null;
        }

        // Left the bot and reached the provider, paid for, with a wallet to credit.
        if ($order->provider_order_id === null
            || $order->payment_status !== 'paid'
            || $order->customer_id === null) {
            return null;
        }

        $owed = self::owed($order);

        if ($owed === null) {
            return null;
        }

        [$kind, $target] = $owed;

        $refunded = null;

        DB::transaction(function () use ($order, $target, &$refunded) {
            // Locked so two runs cannot both read "nothing refunded yet".
            $locked = BotOrder::withoutTenantScope()->whereKey($order->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $already = (string) $locked->refunded_amount;
            $due = bcsub($target, $already, 2);

            if (bccomp($due, '0', 2) <= 0) {
                return;
            }

            $customer = BotCustomer::withoutTenantScope()->find($locked->customer_id);

            if ($customer === null) {
                return;
            }

            $customer->credit($due);

            $locked->update([
                'refunded_amount' => bcadd($already, $due, 2),
                'refunded_at' => now(),
            ]);

            $order->refresh();
            $refunded = $due;
        });

        if ($refunded === null) {
            return null;
        }

        Log::info('Order refunded', ['order' => $order->id, 'kind' => $kind, 'amount' => $refunded]);

        NotifyOrderRefunded::dispatch($order->id, $kind, $refunded)->afterCommit();

        return $refunded;
    }
}
