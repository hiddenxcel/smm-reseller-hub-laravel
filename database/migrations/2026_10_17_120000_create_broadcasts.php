<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements a reseller sends to their customers in one go.
 *
 * `broadcasts` is the message and who it was meant for; `broadcast_recipients`
 * is one row per customer, so progress is real and a run that is cut off can
 * pick up where it stopped. Recipients are written when the broadcast is
 * queued and sent a few at a time by `broadcasts:send`, never in one burst:
 * a number that suddenly sends hundreds of messages is how it gets flagged.
 *
 * `bot_customers.broadcast_opt_out_at` is set when a customer replies STOP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_customers', function (Blueprint $table) {
            $table->timestamp('broadcast_opt_out_at')->nullable();
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            // all | recent_7 | recent_30 | recent_90 | with_orders | with_balance
            $table->string('audience', 20);
            // sending | done | cancelled | paused
            $table->string('status', 12)->default('sending');
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'id'], 'idx_broadcasts_tenant');
            $table->index('status', 'idx_broadcasts_status');
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('customer_phone', 30);
            // whatsapp | telegram
            $table->string('channel', 10);
            // pending | sent | failed | skipped
            $table->string('status', 10)->default('pending');
            // needs_template | opted_out | blocked | no_channel | send_failed
            $table->string('reason', 20)->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->index(['broadcast_id', 'status'], 'idx_broadcast_recipients_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');

        Schema::table('bot_customers', function (Blueprint $table) {
            $table->dropColumn('broadcast_opt_out_at');
        });
    }
};
