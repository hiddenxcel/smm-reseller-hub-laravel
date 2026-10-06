<?php

namespace App\Services\Numbers;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\NumberRental;
use App\Models\PlatformNumber;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hand a platform-owned WhatsApp number to a reseller.
 *
 * Renting exists because connecting your own number is the hardest part of
 * setup: a Meta app, business verification, a permanent token, a webhook.
 * Here the platform keeps all of that, and the reseller gets a number that
 * already works. They never see the token.
 *
 * Paid once. The number is theirs until they hand it back — no expiry, so
 * nothing sweeps rentals and nobody is cut off by a missed renewal.
 */
class RentNumber
{
    /**
     * Claim a number for a tenant.
     *
     * @throws RuntimeException if the number was taken between the reseller
     *                          seeing it and clicking rent.
     */
    public function claim(Tenant $tenant, int $platformNumberId, string $botType): NumberRental
    {
        $this->assertBotTypeIsFree($tenant, $botType);

        return DB::transaction(function () use ($tenant, $platformNumberId, $botType) {
            // The whole point of this line: two resellers can be looking at
            // the same number, and only one may walk away with it. The
            // conditional update is atomic, so the loser gets 0 rows rather
            // than a duplicate rental.
            $claimed = PlatformNumber::whereKey($platformNumberId)
                ->where('status', 'available')
                ->update(['status' => 'rented']);

            if ($claimed !== 1) {
                throw new RuntimeException('That number has just been taken. Please pick another.');
            }

            $number = PlatformNumber::findOrFail($platformNumberId);

            $rental = NumberRental::create([
                'tenant_id' => $tenant->id,
                'platform_number_id' => $number->id,
                'subscription_id' => $this->subscriptionFor($tenant)->id,
                'starts_at' => now(),
                // Paid once — nothing expires it, so this stays open.
                'ends_at' => null,
                'status' => 'active',
            ]);

            $this->attachToTenant($tenant, $number, $botType);

            return $rental;
        });
    }

    /**
     * Give a number back. The rental ends and the number returns to the pool,
     * but the conversations, orders and customers it carried stay with the
     * reseller — those are their business, not the number's.
     */
    public function release(Tenant $tenant, NumberRental $rental): void
    {
        DB::transaction(function () use ($tenant, $rental) {
            $rental->update(['status' => 'revoked', 'ends_at' => now()]);

            PlatformNumber::whereKey($rental->platform_number_id)
                ->update(['status' => 'available']);

            // Deactivated rather than deleted: the message log points at this
            // row, and a reseller who rents again should still see their
            // history.
            TenantWhatsApp::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('phone_number_id', $rental->platformNumber->phone_number_id)
                ->update(['status' => 'inactive']);
        });
    }

    /**
     * Wire the number into the tenant's bot setup.
     *
     * The platform's own token is copied in, encrypted, and hidden on the
     * model — the reseller drives the number without ever holding the
     * credential that controls it.
     */
    private function attachToTenant(Tenant $tenant, PlatformNumber $number, string $botType): void
    {
        TenantWhatsApp::withoutTenantScope()->updateOrCreate(
            ['phone_number_id' => $number->phone_number_id],
            [
                'tenant_id' => $tenant->id,
                'source' => 'rented',
                'cloud_api_token_enc' => $number->cloud_api_token_enc,
                'waba_id' => $number->waba_id,
                'display_number' => $number->display_number,
                'bot_type' => $botType,
                'status' => 'active',
            ],
        );
    }

    /**
     * One bot per number, the same rule the reseller's own numbers follow:
     * two numbers both claiming the order bot would leave an inbound message
     * with no single answer to "whose bot is this?".
     */
    public function assertBotTypeIsFree(Tenant $tenant, string $botType): void
    {
        $conflict = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('bot_type', $botType)
            ->exists();

        if ($conflict) {
            throw new RuntimeException('Another of your numbers already runs that bot.');
        }
    }

    /**
     * Renting is one of the a-la-carte services, so it carries a subscription
     * row like the rest — that is what the rest of the app reads to know the
     * service is held. Bought outright, so it never ends.
     */
    private function subscriptionFor(Tenant $tenant): Subscription
    {
        return Subscription::withoutTenantScope()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'service_key' => ServiceKey::NumberRental,
            ],
            [
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'ends_at' => null,
                'auto_renew' => false,
            ],
        );
    }
}
