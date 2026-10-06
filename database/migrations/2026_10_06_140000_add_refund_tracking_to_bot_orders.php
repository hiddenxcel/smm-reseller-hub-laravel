<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_orders', function (Blueprint $table) {
            // What has gone back to the customer's wallet so far. Refunding is
            // "top up to the target", so a repeated sync can never pay twice.
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('amount');
            $table->timestamp('refunded_at')->nullable()->after('refunded_amount');

            // Units the provider says are still undelivered — what a partial
            // order is refunded against.
            $table->unsignedInteger('remains')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('bot_orders', function (Blueprint $table) {
            $table->dropColumn(['refunded_amount', 'refunded_at', 'remains']);
        });
    }
};
