<?php

namespace App\Services\Admin;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The things an admin can do TO a reseller, as opposed to look at.
 *
 * Each one is audited from in here rather than from the controller, so a second
 * caller cannot perform the action and forget the record. That matters most for
 * the two that move money or cut off a business: `credit` and `suspend`.
 */
class TenantActions
{
    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * Take a reseller offline.
     *
     * Their bots stop and they cannot log in, but nothing is deleted and the
     * wallet balances they hold for their customers are untouched — a
     * suspension is a pause, and it has to be reversible in one click.
     */
    public function suspend(?string $reason = null): void
    {
        if ($this->tenant->status === 'suspended') {
            return;
        }

        $this->tenant->update(['status' => 'suspended']);

        AdminAudit::onTenant('tenants.suspend', $this->tenant->id, [
            'reason' => $reason,
            'wallets_held' => TenantProfile::for($this->tenant)->stats()['walletsHeld'],
        ]);
    }

    public function activate(): void
    {
        if ($this->tenant->status === 'active') {
            return;
        }

        $this->tenant->update(['status' => 'active']);

        AdminAudit::onTenant('tenants.activate', $this->tenant->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(array $attributes): void
    {
        $before = $this->tenant->only(array_keys($attributes));

        $this->tenant->update($attributes);

        AdminAudit::onTenant('tenants.edit', $this->tenant->id, [
            'before' => $before,
            'after' => $attributes,
        ]);
    }

    /**
     * Move referral credit, up or down.
     *
     * The arithmetic happens in the database for the same reason
     * Tenant::spendReferralCredit() does it there: a checkout running at the
     * same moment must not lose an adjustment to a read-then-write, and this is
     * real money a reseller can spend.
     *
     * Returns the new balance.
     */
    public function adjustCredit(float $delta, string $reason): float
    {
        $before = (float) $this->tenant->referral_credit;

        DB::transaction(function () use ($delta) {
            $amount = number_format(abs($delta), 2, '.', '');
            $operator = $delta >= 0 ? '+' : '-';

            Tenant::whereKey($this->tenant->id)->update([
                'referral_credit' => DB::raw("referral_credit {$operator} {$amount}"),
            ]);
        });

        $this->tenant->refresh();

        // A negative balance would be spendable credit the reseller does not
        // have; clamped after the fact rather than refusing, so an admin
        // zeroing an account does not have to know the exact figure.
        if ((float) $this->tenant->referral_credit < 0) {
            $this->tenant->update(['referral_credit' => 0]);
        }

        $after = (float) $this->tenant->referral_credit;

        AdminAudit::onTenant('tenants.credit', $this->tenant->id, [
            'delta' => round($delta, 2),
            'before' => round($before, 2),
            'after' => round($after, 2),
            'reason' => $reason,
        ]);

        return $after;
    }

    /**
     * Set a new password for a reseller who cannot get in.
     *
     * The password itself is never audited — only that it was changed, by whom,
     * and when. An audit trail that carries credentials is a breach waiting for
     * someone to read it.
     */
    public function resetPassword(string $password): void
    {
        $this->tenant->update(['password_hash' => Hash::make($password)]);

        AdminAudit::onTenant('tenants.password', $this->tenant->id);
    }
}
