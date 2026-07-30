<?php

namespace App\Services\Bots;

use App\Models\Tenant;
use App\Models\TenantWhatsApp;

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
        return new WhatsAppCloudMessenger(
            phoneNumberId: $whatsapp->phone_number_id,
            token: (string) $whatsapp->cloud_api_token_enc,
            tenantId: (int) $tenant->id,
            footer: '© '.$tenant->business_name,
        );
    }
}
