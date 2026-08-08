<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the services screen needs beyond what the import wrote.
 *
 * The status change is the substantive one. `inactive` conflated two different
 * decisions a reseller makes: "stop showing this" and "keep showing it but
 * don't take new orders on it" — the second is what you want while a panel is
 * having a bad day. They become `hidden` and `paused`, and `inactive` is
 * migrated to `hidden`, which is what it meant in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_services', function (Blueprint $table) {
            // Shown to the customer in the bot; link_instructions is a
            // different thing (how to supply the link) and stays.
            $table->text('description')->nullable()->after('name');

            $table->boolean('featured')->default(false)->after('sort_order');

            // Big or fraud-prone services a reseller wants to eyeball before
            // the panel is charged.
            $table->boolean('requires_approval')->default(false)->after('featured');

            // Sync bookkeeping: when the panel was last read, and what it said
            // the cost was then — so a cost that moved can be pointed at.
            $table->timestamp('last_synced_at')->nullable()->after('requires_approval');
            $table->decimal('synced_cost_price', 12, 4)->nullable()->after('last_synced_at');

            $table->index(['tenant_id', 'featured'], 'idx_botsvc_tenant_featured');
        });

        // Widen the status set, then narrow the data to match.
        CheckConstraint::drop('bot_services', 'chk_botsvc_status');
        CheckConstraint::in(
            'bot_services',
            'status',
            ['active', 'hidden', 'paused', 'inactive'],
            'chk_botsvc_status',
        );

        DB::table('bot_services')
            ->where('status', 'inactive')
            ->update(['status' => 'hidden']);

        CheckConstraint::drop('bot_services', 'chk_botsvc_status');
        CheckConstraint::in(
            'bot_services',
            'status',
            ['active', 'hidden', 'paused'],
            'chk_botsvc_status',
        );
    }

    public function down(): void
    {
        CheckConstraint::drop('bot_services', 'chk_botsvc_status');
        CheckConstraint::in(
            'bot_services',
            'status',
            ['active', 'hidden', 'paused', 'inactive'],
            'chk_botsvc_status',
        );

        DB::table('bot_services')
            ->whereIn('status', ['hidden', 'paused'])
            ->update(['status' => 'inactive']);

        CheckConstraint::drop('bot_services', 'chk_botsvc_status');
        CheckConstraint::in('bot_services', 'status', ['active', 'inactive'], 'chk_botsvc_status');

        Schema::table('bot_services', function (Blueprint $table) {
            $table->dropIndex('idx_botsvc_tenant_featured');
            $table->dropColumn([
                'description',
                'featured',
                'requires_approval',
                'last_synced_at',
                'synced_cost_price',
            ]);
        });
    }
};
