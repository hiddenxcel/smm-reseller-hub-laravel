<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop and refill were a choice from a short list. A reseller knows better
     * than any list what their service promises ("Low drop, 5% at most",
     * "30 days, free", "Lifetime while the account stays public"), so they are
     * now whatever the reseller writes.
     *
     * What was already saved is carried over as the words it stood for.
     */
    public function up(): void
    {
        Schema::table('bot_services', function (Blueprint $table) {
            $table->string('drop_info', 80)->nullable()->after('speed');
            $table->string('refill_info', 80)->nullable()->after('drop_info');
        });

        DB::table('bot_services')
            ->where(fn ($q) => $q->whereNotNull('drop_guarantee')->orWhereNotNull('refill_type'))
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('bot_services')->where('id', $row->id)->update([
                    'drop_info' => match ($row->drop_guarantee) {
                        'no_drop' => 'No drop',
                        'drop' => 'May drop',
                        default => null,
                    },
                    'refill_info' => match ($row->refill_type) {
                        'none' => 'No refill',
                        'lifetime' => 'Lifetime',
                        'days' => $row->refill_days ? "{$row->refill_days} days" : null,
                        default => null,
                    },
                ]);
            });

        Schema::table('bot_services', function (Blueprint $table) {
            $table->dropColumn(['drop_guarantee', 'refill_type', 'refill_days']);
        });
    }

    public function down(): void
    {
        Schema::table('bot_services', function (Blueprint $table) {
            $table->string('drop_guarantee', 10)->nullable()->after('speed');
            $table->string('refill_type', 10)->nullable()->after('drop_guarantee');
            $table->unsignedSmallInteger('refill_days')->nullable()->after('refill_type');
        });

        Schema::table('bot_services', function (Blueprint $table) {
            $table->dropColumn(['drop_info', 'refill_info']);
        });
    }
};