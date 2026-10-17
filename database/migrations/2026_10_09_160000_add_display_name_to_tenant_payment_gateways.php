<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name a reseller wants their customers to see for a gateway.
 *
 * The bot's payment menu has always shown the provider's own name ("FimiPay
 * (Ghana — GHS)"). A reseller may prefer "Pay with M-Pesa" or their own brand,
 * so they can set one. 24 characters because that is all a WhatsApp list row
 * will show.
 *
 * Additive and nullable: blank means the default name, so nothing changes for a
 * reseller who never sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_payment_gateways', function (Blueprint $table) {
            $table->string('display_name', 24)->nullable()->after('gateway');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_payment_gateways', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
