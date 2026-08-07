<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the AI add-on has cost a reseller so far.
 *
 * The DeepSeek key belongs to the reseller, so every answer is billed to them
 * by DeepSeek directly — we never see the invoice. Without a count they have
 * no way to connect a surprising bill to what their bot actually did.
 *
 * Deliberately a counter and not a limit: nothing here stops an answer. It
 * exists so a reseller can see the shape of their usage, and so a cap can be
 * added later against real numbers rather than a guess.
 *
 * `answers_today` resets on first use of a new day rather than by a scheduled
 * job — a reset that depends on cron silently stops happening when cron does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_ai', function (Blueprint $table) {
            $table->unsignedInteger('answers_today')->default(0);
            $table->unsignedBigInteger('answers_total')->default(0);
            // Which day answers_today is counting. Null until the first answer.
            $table->date('counting_day')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_ai', function (Blueprint $table) {
            $table->dropColumn(['answers_today', 'answers_total', 'counting_day']);
        });
    }
};
