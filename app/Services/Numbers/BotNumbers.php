<?php

namespace App\Services\Numbers;

use App\Models\NumberRental;
use App\Models\PlatformNumber;
use App\Models\TenantWhatsApp;

/**
 * What a bot's "Number" tab shows: the number this bot answers on, what could
 * be rented for it, and what is needed to connect one of the reseller's own.
 *
 * A number belongs to one bot, so each bot's tab lists only its own. A rented
 * number carries the rental that bills it, so the tab can hand it back.
 */
class BotNumbers
{
    /** @return array<string, mixed> */
    public static function for(int $tenantId, string $bot): array
    {
        $rentals = NumberRental::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with('platformNumber')
            ->get()
            ->keyBy(fn (NumberRental $rental) => $rental->platformNumber?->phone_number_id);

        return [
            'bot' => $bot,
            'webhookUrl' => route('webhooks.whatsapp'),
            'verifyToken' => (string) config('services.meta.verify_token'),
            'numbers' => TenantWhatsApp::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('bot_type', $bot)
                ->get()
                ->map(fn (TenantWhatsApp $number) => [
                    'id' => $number->id,
                    'display_number' => $number->display_number,
                    'phone_number_id' => $number->phone_number_id,
                    'source' => $number->source,
                    'status' => $number->status,
                    'rental_id' => $rentals->get($number->phone_number_id)?->id,
                    'country' => $rentals->get($number->phone_number_id)?->platformNumber?->country,
                ])
                ->all(),
            'rentable' => PlatformNumber::where('status', 'available')
                ->orderBy('country')
                ->orderBy('display_number')
                ->get()
                ->map(fn (PlatformNumber $number) => [
                    'id' => $number->id,
                    'display_number' => $number->display_number,
                    'country' => $number->country,
                    'currency' => $number->currency,
                    'price' => (float) $number->monthly_cost,
                ])
                ->all(),
        ];
    }
}
