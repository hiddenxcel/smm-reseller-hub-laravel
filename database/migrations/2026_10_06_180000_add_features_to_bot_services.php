<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_services', function (Blueprint $table) {
            // What a customer is told about a service before they order it. All
            // optional: a line the reseller has not filled in is simply not shown.
            $table->string('quality', 80)->nullable()->after('description');
            $table->string('speed', 80)->nullable()->after('quality');
            // no_drop | drop
            $table->string('drop_guarantee', 10)->nullable()->after('speed');
            // none | days | lifetime, and how many days when it is "days"
            $table->string('refill_type', 10)->nullable()->after('drop_guarantee');
            $table->unsignedSmallInteger('refill_days')->nullable()->after('refill_type');
        });
    }

    public function down(): void
    {
        Schema::table('bot_services', function (Blueprint $table) {
            $table->dropColumn(['quality', 'speed', 'drop_guarantee', 'refill_type', 'refill_days']);
        });
    }
};