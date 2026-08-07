<?php

namespace App\Services\Customers;

use App\Models\BotCustomer;
use App\Services\Bots\BotSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Referrals between a reseller's own customers.
 *
 * This is a different scheme from ReferralReward, which is the platform paying
 * a reseller for introducing another reseller. Here one shopper introduces
 * another and earns a cut of their first top-up, paid straight into the
 * referrer's wallet.
 *
 * Ported from the old platform, where it was only half-built: codes were
 * generated and `showReferral` read the totals, but nothing ever set
 * `referred_by`, so `creditReferralEarning` could not fire and every customer
 * saw "0 referrals, 0.00 earned" forever. The missing link is `claim()`.
 */
class CustomerReferrals
{
    /**
     * Codes are shared out loud and typed back in, so the alphabet leaves out
     * the pairs people confuse: O/0, I/1, S/5, B/8.
     */
    private const ALPHABET = '234679ACDEFGHJKLMNPQRTUVWXYZ';

    private const CODE_LENGTH = 7;

    /** Give a customer a code if they do not have one yet. */
    public static function assignCode(BotCustomer $customer): string
    {
        if (filled($customer->referral_code)) {
            return $customer->referral_code;
        }

        // The unique index is on (tenant_id, referral_code), so a collision is
        // possible and simply means trying again. Bounded so a misconfigured
        // alphabet cannot spin forever.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'C'.self::randomCode();

            $taken = BotCustomer::withoutTenantScope()
                ->where('tenant_id', $customer->tenant_id)
                ->where('referral_code', $code)
                ->exists();

            if ($taken) {
                continue;
            }

            $customer->forceFill(['referral_code' => $code])->save();

            return $code;
        }

        Log::warning('Could not allocate a referral code', [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
        ]);

        return '';
    }

    /**
     * Attach a new customer to whoever referred them.
     *
     * Refused when: the code is unknown, it is the customer's own, or they are
     * already attached to someone. The `referred_by is null` predicate lives
     * in the UPDATE rather than in a preceding read, so two messages arriving
     * together cannot both claim the same customer.
     */
    public static function claim(BotCustomer $customer, string $code): bool
    {
        $code = mb_strtoupper(trim($code));

        if ($code === '' || $code === $customer->referral_code) {
            return false;
        }

        $referrer = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $customer->tenant_id)
            ->where('referral_code', $code)
            ->first();

        if ($referrer === null || $referrer->id === $customer->id) {
            return false;
        }

        $claimed = BotCustomer::withoutTenantScope()
            ->whereKey($customer->id)
            ->whereNull('referred_by')
            ->update(['referred_by' => $referrer->id]) === 1;

        if ($claimed) {
            $customer->refresh();
        }

        return $claimed;
    }

    /**
     * Pay the referrer their cut of a first top-up.
     *
     * Only the first deposit earns anything, and `first_deposit_done` is
     * flipped by a conditional UPDATE that doubles as the guard: a retried
     * gateway webhook finds the flag already set and pays nothing. Without
     * that, every retry of the same payment would credit the referrer again.
     */
    public static function payFirstDepositBonus(BotCustomer $customer, string $depositAmount): void
    {
        $claimedDeposit = BotCustomer::withoutTenantScope()
            ->whereKey($customer->id)
            ->where('first_deposit_done', false)
            ->update(['first_deposit_done' => true]) === 1;

        if (! $claimedDeposit) {
            return; // not their first deposit, or another request got there first
        }

        $customer->refresh();

        if ($customer->referred_by === null) {
            return;
        }

        $percent = (float) data_get(
            BotSettings::for($customer->tenant_id, 'order'),
            'shop.referral_percent',
            0,
        );

        if ($percent <= 0) {
            return;
        }

        $bonus = round((float) $depositAmount * ($percent / 100), 2);

        if ($bonus <= 0) {
            return;
        }

        $referrer = BotCustomer::withoutTenantScope()->find($customer->referred_by);

        if ($referrer === null) {
            return;
        }

        // Balance and earnings move together — earnings is the running total
        // of what referrals have paid, and the two drifting apart would make
        // the customer's own referral screen wrong.
        DB::transaction(function () use ($referrer, $bonus) {
            $referrer->credit((string) $bonus);

            BotCustomer::withoutTenantScope()
                ->whereKey($referrer->id)
                ->update([
                    'referral_earnings' => DB::raw(
                        'referral_earnings + '.self::numericLiteral((string) $bonus, $referrer)
                    ),
                ]);
        });
    }

    /** How many customers this one has brought in. */
    public static function countFor(BotCustomer $customer): int
    {
        return BotCustomer::withoutTenantScope()
            ->where('referred_by', $customer->id)
            ->count();
    }

    private static function randomCode(): string
    {
        $alphabet = self::ALPHABET;
        $length = mb_strlen($alphabet);
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= mb_substr($alphabet, random_int(0, $length - 1), 1);
        }

        return $code;
    }

    /**
     * Same reasoning as BotCustomer::numeric — the arithmetic has to happen in
     * the database to stay atomic, and Eloquent's update() takes no bindings,
     * so the value is validated as a decimal before it is interpolated.
     */
    private static function numericLiteral(string $amount, BotCustomer $customer): string
    {
        if (! preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $amount)) {
            throw new \InvalidArgumentException("Invalid bonus amount: {$amount}");
        }

        return $customer->getConnection()->getDriverName() === 'pgsql'
            ? "{$amount}::numeric"
            : $amount;
    }
}
