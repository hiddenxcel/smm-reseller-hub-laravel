<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the bots told the reseller's team, and whether it got there.
 *
 * A message to a staff number is logged with the rest of the conversation
 * whether or not WhatsApp accepted it, so that log cannot say who actually
 * heard. This is the record that can: one row per person told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bot_type', 20);
            $table->string('to_phone', 30);
            $table->string('message', 500);
            // sent | failed
            $table->string('status', 10);
            // never | closed | rejected, when it failed
            $table->string('reason', 20)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'bot_type', 'id'], 'idx_staff_alerts_recent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_alerts');
    }
};
