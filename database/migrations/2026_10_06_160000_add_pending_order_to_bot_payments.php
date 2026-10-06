<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_payments', function (Blueprint $table) {
            // The order the customer was paying for, kept on the payment itself.
            // The conversation is a poor place to remember it: a stray "hi", a
            // timeout or a message to support clears the conversation and leaves
            // a wallet credited with the order forgotten.
            $table->json('pending_order')->nullable()->after('binance_order_id');

            // When a gateway was last asked whether this was paid, so a payment
            // is checked often while it is fresh and rarely once it is old.
            $table->timestamp('last_checked_at')->nullable()->after('pending_order');
        });
    }

    public function down(): void
    {
        Schema::table('bot_payments', function (Blueprint $table) {
            $table->dropColumn(['pending_order', 'last_checked_at']);
        });
    }
};