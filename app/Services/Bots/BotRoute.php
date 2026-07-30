<?php

namespace App\Services\Bots;

/**
 * Why an inbound message was or wasn't dispatched. Returned instead of a bare
 * string so callers and logs agree on the vocabulary.
 */
enum BotRoute: string
{
    case HandledOrder = 'handled_order';
    case HandledSupport = 'handled_support';

    /** Payload carried no message (e.g. a delivery/read status callback). */
    case NoMessage = 'no_message';

    /** phone_number_id belongs to no tenant on this platform. */
    case UnknownNumber = 'unknown_number';

    /** Tenant is suspended — their bots stop immediately. */
    case TenantInactive = 'tenant_inactive';

    /** The service this message needs has no active subscription. */
    case GateLocked = 'gate_locked';

    /** Sender is over the tenant's anti-spam threshold. */
    case SpamBlocked = 'spam_blocked';
}
