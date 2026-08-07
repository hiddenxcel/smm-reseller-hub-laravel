<?php

namespace App\Services\Api;

use App\Models\BotOrder;
use App\Services\Orders\OrderStatus;

/**
 * How an order looks to an API caller.
 *
 * One place, because `status` and `orders` must not describe the same order
 * differently — a client that polls one and reconciles with the other will
 * find any disagreement.
 *
 * The status reported is the folded group (OrderStatus), not the panel's raw
 * wording. Panels say "Completed", "COMPLETE" and "In progress" for the same
 * three or four states, and a caller cannot be expected to know every panel's
 * spelling. The convention's own vocabulary is used: Pending, In progress,
 * Completed, Canceled.
 */
final class OrderView
{
    public static function of(BotOrder $order): array
    {
        return [
            'charge' => (string) ($order->charge ?? $order->amount ?? '0'),
            'start_count' => '0',
            'status' => self::label($order),
            'remains' => '0',
            'currency' => 'USD',
        ];
    }

    /**
     * A payment that failed outranks whatever the panel last said: an order
     * that was never paid for is not "in progress", however the panel has it.
     */
    private static function label(BotOrder $order): string
    {
        if ($order->payment_status === 'failed') {
            return 'Canceled';
        }

        return match (OrderStatus::fold($order->status)) {
            OrderStatus::COMPLETED => 'Completed',
            OrderStatus::PROCESSING => 'In progress',
            OrderStatus::FAILED => 'Canceled',
            default => 'Pending',
        };
    }
}
