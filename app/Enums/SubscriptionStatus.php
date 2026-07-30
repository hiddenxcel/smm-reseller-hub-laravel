<?php

namespace App\Enums;

/**
 * A subscription's lifecycle. The dashboard reads this as a tri-state:
 * active (paid, live), sandbox (explorable + self-test only), or locked
 * (anything else).
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Sandbox = 'sandbox';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
