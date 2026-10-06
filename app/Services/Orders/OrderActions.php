<?php

namespace App\Services\Orders;

use App\Jobs\SubmitOrderToPanel;
use App\Models\BotOrder;
use App\Services\Panel\SmmProviderClient;

/**
 * What a reseller may do to an order, and doing it.
 *
 * Which actions are offered is decided here rather than in the UI, so a button
 * cannot appear for an order the server would refuse — and the same check runs
 * again on execution, because the front end is not a permission boundary.
 */
class OrderActions
{
    public const RETRY = 'retry';

    public const REFILL = 'refill';

    public const CANCEL = 'cancel';

    public const MARK = 'mark';

    public const ALL = [self::RETRY, self::REFILL, self::CANCEL, self::MARK];

    /**
     * @return list<string>
     */
    public static function availableFor(BotOrder $order): array
    {
        $available = [];
        $group = OrderStatus::fold($order->status);

        // Retry is for an order the customer already paid for that never
        // reached the panel — that is the case worth money to fix.
        if ($order->provider_order_id === null
            && $order->panel_id !== null
            && $order->payment_status === 'paid') {
            $available[] = self::RETRY;
        }

        // Refill only means anything once the panel has finished delivering.
        if ($order->provider_order_id !== null && $group === OrderStatus::COMPLETED) {
            $available[] = self::REFILL;
        }

        // Panels reject a cancel once delivery is under way, but they are the
        // authority on that, not us — so it stays offered until the order is
        // finished one way or the other.
        if ($order->provider_order_id !== null
            && ! in_array($group, [OrderStatus::COMPLETED, OrderStatus::FAILED], true)) {
            $available[] = self::CANCEL;
        }

        // Setting a status by hand is always allowed; see markStatus for why
        // it is not as final as it looks.
        $available[] = self::MARK;

        return $available;
    }

    public static function isAvailable(BotOrder $order, string $action): bool
    {
        return in_array($action, self::availableFor($order), true);
    }

    /**
     * Re-submit an order the panel never accepted.
     *
     * Queued rather than called inline for the same reason the original
     * submission is: a panel can take 30 seconds to answer, and the reseller
     * should not be staring at a spinner for it. SubmitOrderToPanel already
     * refuses to act on an order that has a provider id, so a double click
     * cannot place the order twice.
     */
    public static function retry(BotOrder $order): ActionResult
    {
        // Back to pending, so the customer's view and the reseller's list agree
        // that it is on its way again; the job moves it on or fails it again.
        $order->update(['order_error' => null, 'status' => 'Pending']);

        SubmitOrderToPanel::dispatch($order->id);

        return ActionResult::ok('Sent to the panel again.');
    }

    public static function refill(BotOrder $order): ActionResult
    {
        $panel = $order->panel;

        if ($panel === null) {
            return ActionResult::failed('That order has no panel to refill from.');
        }

        $result = SmmProviderClient::forPanel($panel)->refill((string) $order->provider_order_id);

        if ($result->failed) {
            return ActionResult::failed($result->message ?? 'The panel refused the refill.');
        }

        $order->update(['refill_status' => 'requested']);

        return ActionResult::ok('Refill requested.');
    }

    public static function cancel(BotOrder $order): ActionResult
    {
        $panel = $order->panel;

        if ($panel === null) {
            return ActionResult::failed('That order has no panel to cancel on.');
        }

        $result = SmmProviderClient::forPanel($panel)->cancel((string) $order->provider_order_id);

        if ($result->failed) {
            return ActionResult::failed($result->message ?? 'The panel refused the cancellation.');
        }

        $order->update(['status' => 'Canceled']);

        // The provider has the order and has cancelled it: the customer is
        // owed their money back (if the shop has refunds switched on).
        $refunded = app(RefundOrder::class)->handle($order);

        return ActionResult::ok(
            $refunded === null ? 'Cancellation requested.' : "Cancelled, and {$refunded} returned to the customer's wallet.",
        );
    }

    /**
     * Set a status by hand.
     *
     * Worth being plain about the limit: for an order the panel is tracking,
     * the status sync will overwrite this the next time it runs. It is meant
     * for orders the panel has no opinion on — one placed by hand, or one that
     * never got there — and the UI says so.
     */
    public static function markStatus(BotOrder $order, string $group): ActionResult
    {
        if (! OrderStatus::isValidGroup($group)) {
            return ActionResult::failed('That is not a status.');
        }

        $order->update(['status' => self::MARK_LABELS[$group]]);

        return ActionResult::ok('Status updated.');
    }

    /**
     * What a hand-set status is written as. Stored in the panel's own vocabulary
     * so the value folds back into the same group it was chosen from.
     */
    public const MARK_LABELS = [
        OrderStatus::PENDING => 'Pending',
        OrderStatus::PROCESSING => 'In progress',
        OrderStatus::COMPLETED => 'Completed',
        OrderStatus::FAILED => 'Canceled',
    ];
}
