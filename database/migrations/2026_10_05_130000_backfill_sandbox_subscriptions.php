<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put every existing reseller's bots into sandbox if they hold no subscription.
 *
 * New resellers get this at sign-up (see StartSandbox). Those who registered
 * before that existed have no row at all, and without one their test numbers
 * are answered with "this service is currently paused".
 *
 * Only a missing row is filled in. Anyone with a subscription for the service,
 * whatever its state, is untouched — so this cannot downgrade a paying
 * reseller. Written against the table rather than the models so it keeps
 * working however the models change later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (['order_bot', 'support_bot'] as $service) {
            $missing = DB::table('tenants')
                ->whereNotExists(function ($query) use ($service) {
                    $query->selectRaw('1')
                        ->from('subscriptions')
                        ->whereColumn('subscriptions.tenant_id', 'tenants.id')
                        ->where('subscriptions.service_key', $service);
                })
                ->pluck('id');

            foreach ($missing->chunk(200) as $ids) {
                DB::table('subscriptions')->insert(
                    $ids->map(fn ($id) => [
                        'tenant_id' => $id,
                        'service_key' => $service,
                        'status' => 'sandbox',
                        'auto_renew' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );
            }
        }
    }

    public function down(): void
    {
        // Left in place: a sandbox row is harmless, and removing them cannot
        // tell the ones added here from the ones created at sign-up.
    }
};
