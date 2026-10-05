<?php

namespace App\Services\Billing;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Gives a new reseller the two bots in sandbox.
 *
 * The router lets a registered test number through a bot only while that bot's
 * subscription is in sandbox (see BotRouter::mayRun) — and nothing else ever
 * put a subscription into that state. So a reseller who added their own phone as
 * a test number, followed every instruction, and messaged the bot was told
 * "this service is currently paused" for good: the exception existed in the
 * gate but the row it looks for was never written.
 *
 * Sandbox is not live. The public gate still refuses everyone else; this only
 * makes "test it before you pay", which the site promises, true.
 *
 * Idempotent: a service that already has any subscription — paid, expired,
 * cancelled — is left alone, so running this can never downgrade anyone.
 */
class StartSandbox
{
    /** The services that are set up and tried before being paid for. */
    private const SERVICES = [ServiceKey::OrderBot, ServiceKey::SupportBot];

    public function __invoke(Tenant $tenant): void
    {
        foreach (self::SERVICES as $service) {
            $exists = Subscription::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->forService($service)
                ->exists();

            if ($exists) {
                continue;
            }

            Subscription::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'service_key' => $service,
                'status' => SubscriptionStatus::Sandbox,
                'auto_renew' => false,
            ]);
        }
    }
}
