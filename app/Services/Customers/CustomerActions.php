<?php

namespace App\Services\Customers;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a reseller may do to a customer, and doing it.
 *
 * The wallet is the part that matters. In the old platform an adjustment was a
 * bare `UPDATE bot_customers SET balance = balance + ?` with nothing written
 * anywhere — so when a customer asked "where did my money go", there was no
 * answer, and when a reseller made a mistake there was nothing to trace. Here
 * every adjustment writes a bot_payments row alongside the balance change, in
 * one transaction, with the reason the reseller typed.
 */
class CustomerActions
{
    /** The gateway name manual adjustments are recorded under. */
    public const MANUAL_GATEWAY = 'manual';

    /**
     * Move a customer's balance by $delta (negative to take money off).
     *
     * The deduction path reuses `debit()`, whose `balance >= ?` predicate is
     * what stops a wallet going negative under concurrent writes — a check in
     * PHP followed by an update would not.
     */
    public static function adjustWallet(
        BotCustomer $customer,
        string $delta,
        ?string $reason = null,
    ): ActionOutcome {
        if (! preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $delta)) {
            return ActionOutcome::failed('That is not an amount.');
        }

        if (bccomp($delta, '0', 2) === 0) {
            return ActionOutcome::failed('Enter an amount above zero.');
        }

        $isCredit = bccomp($delta, '0', 2) === 1;
        $magnitude = ltrim($delta, '-');

        return DB::transaction(function () use ($customer, $delta, $magnitude, $isCredit, $reason) {
            if ($isCredit) {
                $customer->credit($magnitude);
            } elseif (! $customer->debit($magnitude)) {
                return ActionOutcome::failed(
                    'That would put the wallet below zero. Their balance is '
                    .number_format((float) $customer->balance, 2).'.'
                );
            }

            // The audit row. `type` stays within the column's check constraint;
            // the gateway is what marks it as done by hand.
            BotPayment::withoutTenantScope()->create([
                'tenant_id' => $customer->tenant_id,
                'type' => 'wallet_topup',
                'customer_id' => $customer->id,
                'gateway' => self::MANUAL_GATEWAY,
                'transaction_ref' => 'manual-'.Str::lower(Str::ulid()),
                // Recorded as the signed movement, so a deduction reads as one.
                'amount' => $delta,
                'status' => 'success',
            ]);

            $customer->refresh();

            return ActionOutcome::ok(
                ($isCredit ? 'Added ' : 'Deducted ').number_format((float) $magnitude, 2)
                .'. New balance: '.number_format((float) $customer->balance, 2).'.',
                ['balance' => (float) $customer->balance, 'reason' => $reason],
            );
        });
    }

    /**
     * Stop the bot answering this number.
     *
     * Blocking does not delete anything — their orders, payments and history
     * stay exactly where they are. It is a decision that can be undone.
     */
    public static function block(BotCustomer $customer): ActionOutcome
    {
        if ($customer->blocked_at !== null) {
            return ActionOutcome::ok('That customer is already blocked.');
        }

        $customer->forceFill(['blocked_at' => now()])->save();

        return ActionOutcome::ok('Blocked. The bot will no longer reply to this number.');
    }

    public static function unblock(BotCustomer $customer): ActionOutcome
    {
        $customer->forceFill(['blocked_at' => null])->save();

        return ActionOutcome::ok('Unblocked. The bot will answer this number again.');
    }

    /**
     * The details a reseller keeps themselves. Phone is not among them: it is
     * the bot's routing key and the unique key on the table, and editing it
     * would silently detach the customer from their own message history.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function update(BotCustomer $customer, array $attributes): ActionOutcome
    {
        $customer->fill([
            'name' => $attributes['name'] ?? null,
            'email' => $attributes['email'] ?? null,
            'country' => isset($attributes['country'])
                ? mb_strtoupper((string) $attributes['country'])
                : null,
            'notes' => $attributes['notes'] ?? null,
            'tags' => self::normaliseTags($attributes['tags'] ?? []),
        ]);

        if (filled($attributes['lang'] ?? null)) {
            $customer->lang = $attributes['lang'];
        }

        $customer->save();

        return ActionOutcome::ok('Customer updated.');
    }

    /**
     * Delete a customer.
     *
     * Refused while they have orders. `bot_orders.customer_id` is nullOnDelete,
     * so the orders would survive as anonymous rows — the reseller's revenue
     * history would stay intact but stop being attributable to anyone, which
     * is a strange half-state to leave behind by accident. Blocking is almost
     * always what was actually wanted.
     */
    public static function delete(BotCustomer $customer): ActionOutcome
    {
        if ($customer->orders()->exists()) {
            return ActionOutcome::failed(
                'That customer has orders, so they cannot be deleted. Block them instead — '
                .'their history stays and the bot stops replying.'
            );
        }

        $customer->delete();

        return ActionOutcome::ok('Customer deleted.');
    }

    /**
     * Tags are free text a reseller types, so they are trimmed, de-duplicated
     * case-insensitively, capped in length and in number.
     *
     * @return list<string>
     */
    private static function normaliseTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return collect($tags)
            ->filter(fn ($tag) => is_string($tag))
            ->map(fn (string $tag) => mb_substr(trim($tag), 0, 40))
            ->filter(fn (string $tag) => $tag !== '')
            ->unique(fn (string $tag) => mb_strtolower($tag))
            ->take(20)
            ->values()
            ->all();
    }
}
