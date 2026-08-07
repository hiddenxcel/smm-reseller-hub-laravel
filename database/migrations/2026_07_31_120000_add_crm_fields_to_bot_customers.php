<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the customers screen needs that the bot has no way to learn.
 *
 * A customer arrives as a phone number and nothing else — WhatsApp gives the
 * bot no country, no email, no notes. These are for the reseller to fill in by
 * hand from what they know about the person, so every column is nullable and
 * nothing here is required for the bot to work.
 *
 * `blocked_at` is the exception: it is not a note, it is a decision. It gates
 * whether the bot answers this number at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_customers', function (Blueprint $table) {
            $table->string('email', 190)->nullable()->after('name');
            $table->string('country', 2)->nullable()->after('lang');
            $table->text('notes')->nullable()->after('country');

            // Stored as JSON rather than a join table: tags here are free
            // labels a reseller invents ("wholesale", "slow payer"), never
            // queried across customers, and a whole table for that is weight
            // without a use.
            $table->json('tags')->nullable()->after('notes');

            // A timestamp rather than a boolean — "since when" is the first
            // thing anyone asks about a blocked customer.
            $table->timestamp('blocked_at')->nullable()->after('tags');

            // The customers list sorts and filters on recency constantly.
            // Without this it is a correlated subquery into bot_messages for
            // every row on every page.
            $table->timestamp('last_seen_at')->nullable()->after('blocked_at');

            $table->index(['tenant_id', 'last_seen_at'], 'idx_botcust_tenant_seen');
            $table->index(['tenant_id', 'blocked_at'], 'idx_botcust_tenant_blocked');
        });
    }

    public function down(): void
    {
        Schema::table('bot_customers', function (Blueprint $table) {
            $table->dropIndex('idx_botcust_tenant_seen');
            $table->dropIndex('idx_botcust_tenant_blocked');

            $table->dropColumn([
                'email',
                'country',
                'notes',
                'tags',
                'blocked_at',
                'last_seen_at',
            ]);
        });
    }
};
