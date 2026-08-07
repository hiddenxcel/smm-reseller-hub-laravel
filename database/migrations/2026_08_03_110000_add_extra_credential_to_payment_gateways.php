<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third credential slot.
 *
 * Two was enough while every gateway needed a key and a webhook secret. It is
 * not enough for the ones added since:
 *
 *   PayPal  — client id, secret, AND the webhook id from their dashboard,
 *             which is what their verification API keys on.
 *   Pesapal — consumer key, consumer secret, AND the IPN id returned when the
 *             notification URL is registered, which every order must carry.
 *
 * Named for what it is rather than for either of those: the next gateway that
 * needs a third value should not have to add a fourth column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_payment_gateways', function (Blueprint $table) {
            $table->text('extra_enc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_payment_gateways', function (Blueprint $table) {
            $table->dropColumn('extra_enc');
        });
    }
};
