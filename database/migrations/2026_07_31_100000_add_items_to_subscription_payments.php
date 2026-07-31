<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One payment can buy several things at once — the order bot, the support
 * bot, and a number, on one checkout.
 *
 * The existing plan_id / subscription_id columns hold one service each, which
 * was enough when a payment meant one plan. Rather than a payment row per
 * line (three redirects to pay one bill), the cart is recorded here and the
 * webhook replays it.
 *
 * JSON rather than a line-items table because nothing queries across it: it
 * is read once, by the webhook, for the payment it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->json('items')->nullable()->after('months');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn('items');
        });
    }
};
