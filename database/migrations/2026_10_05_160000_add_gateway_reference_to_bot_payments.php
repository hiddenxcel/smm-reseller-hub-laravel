<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember the reference the gateway gave a payment, as well as ours.
 *
 * A webhook has to be matched back to its payment, and gateways disagree about
 * what they send back. Some echo the reference we supplied. Others — Snippe's
 * own "SN…" id, M-Pesa's CheckoutRequestID — send only the id they issued. With
 * nowhere to keep it, those payments could be started but never recognised when
 * they cleared, and the customer paid for nothing.
 *
 * Additive and nullable: existing rows, and gateways that echo our reference,
 * are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_payments', function (Blueprint $table) {
            $table->string('gateway_reference', 160)->nullable()->after('transaction_ref');
            $table->index('gateway_reference', 'idx_botpay_gateway_ref');
        });
    }

    public function down(): void
    {
        Schema::table('bot_payments', function (Blueprint $table) {
            $table->dropIndex('idx_botpay_gateway_ref');
            $table->dropColumn('gateway_reference');
        });
    }
};
