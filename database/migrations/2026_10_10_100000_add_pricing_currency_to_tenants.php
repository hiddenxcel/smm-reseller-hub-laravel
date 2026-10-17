<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reseller's own choice of currency for the dashboard: their service
 * prices, their customers' wallets, and their orders. Deliberately separate
 * from `tenant_bot_settings`'s `shop.currency`, which only ever labelled an
 * amount for display in a WhatsApp message and never changed what was
 * actually stored — this column, once set, is the currency new prices and
 * balances are genuinely written in from that point on.
 *
 * Null means "USD, nothing has changed" — every tenant that never touches
 * this setting keeps behaving exactly as before. `pricing_currency_changed_at`
 * is the boundary: rows written before it are USD regardless of what this
 * column says later, so a tenant switching currency (and back) can never
 * make historical data look like it was something it was not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('pricing_currency', 3)->nullable()->after('lang');
            $table->timestamp('pricing_currency_changed_at')->nullable()->after('pricing_currency');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['pricing_currency', 'pricing_currency_changed_at']);
        });
    }
};
