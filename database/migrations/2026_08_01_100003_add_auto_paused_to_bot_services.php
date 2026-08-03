<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who paused this service — the sync, or the reseller?
 *
 * The sync pauses anything the panel stops listing, and lifts that pause when
 * it comes back. Without knowing which pauses were its own, it would also
 * switch on a service the reseller had deliberately paused, which is the
 * platform overruling a decision someone made on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_services', function (Blueprint $table) {
            $table->boolean('auto_paused')->default(false)->after('requires_approval');
        });
    }

    public function down(): void
    {
        Schema::table('bot_services', function (Blueprint $table) {
            $table->dropColumn('auto_paused');
        });
    }
};
