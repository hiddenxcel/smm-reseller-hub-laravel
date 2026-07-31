<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much referral credit a payment consumed.
 *
 * Recorded rather than inferred because the credit is taken off the tenant's
 * balance the moment checkout starts — if the payment then fails or is
 * abandoned, this is what says how much to give back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->decimal('credit_applied', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn('credit_applied');
        });
    }
};
