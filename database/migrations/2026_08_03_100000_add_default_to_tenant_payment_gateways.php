<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which gateway a customer is sent to when they top up.
 *
 * A reseller can have several connected at once — mobile money for local
 * customers, crypto for the rest — and picking one by row order, as
 * `firstUsableFor` did, means the choice silently changes the moment they
 * connect another. This makes it theirs to decide.
 *
 * The uniqueness of "at most one default per tenant" is enforced by a partial
 * index rather than application code: two browser tabs could otherwise both
 * set one, and the bot would be back to picking by order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_payment_gateways', function (Blueprint $table) {
            $table->boolean('is_default')->default(false);
        });

        // Resellers who already connected something keep working without
        // touching the page: their first active gateway becomes the default.
        $firsts = DB::table('tenant_payment_gateways')
            ->select(DB::raw('MIN(id) AS id'))
            ->where('status', 'active')
            ->groupBy('tenant_id')
            ->pluck('id');

        if ($firsts->isNotEmpty()) {
            DB::table('tenant_payment_gateways')
                ->whereIn('id', $firsts)
                ->update(['is_default' => true]);
        }

        DB::statement(
            'CREATE UNIQUE INDEX tenant_payment_gateways_one_default
             ON tenant_payment_gateways (tenant_id)
             WHERE is_default',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenant_payment_gateways_one_default');

        Schema::table('tenant_payment_gateways', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
