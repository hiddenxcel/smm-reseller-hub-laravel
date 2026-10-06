<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_customers', function (Blueprint $table) {
            // The currency this customer likes to see prices in. Display only:
            // the wallet, the orders and every payment stay in the shop's own
            // currency, so a change of rate can never change what anyone owes.
            // Null means "the shop's currency".
            $table->string('currency', 3)->nullable()->after('lang');
        });
    }

    public function down(): void
    {
        Schema::table('bot_customers', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};