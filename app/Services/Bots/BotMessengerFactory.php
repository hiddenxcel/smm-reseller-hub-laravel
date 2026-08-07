<?php

namespace App\Services\Bots;

use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use Illuminate\Support\Arr;

/**
 * Builds the send-side client for a channel, bound to the tenant's own
 * credentials — each reseller sends from their own WhatsApp number.
 *
 * Exists so the router can be handed a fake messenger in tests instead of
 * constructing an HTTP client inline.
 */
class BotMessengerFactory
{
    public function forWhatsApp(TenantWhatsApp $whatsapp, Tenant $tenant): BotMessenger
    {
        $bot = $whatsapp->bot_type ?? 'order';

        return new WhatsAppCloudMessenger(
            phoneNumberId: $whatsapp->phone_number_id,
            token: (string) $whatsapp->cloud_api_token_enc,
            tenantId: (int) $tenant->id,
            footer: '© '.$tenant->business_name,
            botType: $bot,
            // The reseller's chosen language, so a template override is looked
            // up in the locale their bot actually speaks.
            lang: BotLang::normalize(
                Arr::get(BotSettings::for((int) $tenant->id, $bot), 'shop.lang', BotLang::DEFAULT),
            ),
        );
    }
}
