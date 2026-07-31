<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One number runs exactly one bot — 'both' is gone.
 *
 * A shared number meant one tenant_whatsapp row belonged to two modules at
 * once, so any per-bot screen editing it would silently edit the other bot's
 * number too. It also forced the router to guess which bot an ambiguous
 * message was for, which is a guess it now never has to make.
 *
 * A reseller running both services needs two numbers; renting one is a few
 * clicks, which is why that was built first.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing in production uses 'both', but a dev database might — send
        // those to the order bot, the busier of the two.
        DB::table('tenant_whatsapp')->where('bot_type', 'both')->update(['bot_type' => 'order']);

        CheckConstraint::drop('tenant_whatsapp', 'chk_wa_bot_type');
        CheckConstraint::in('tenant_whatsapp', 'bot_type', ['order', 'support'], 'chk_wa_bot_type');

        Schema::table('tenant_whatsapp', function (Blueprint $table) {
            $table->string('bot_type', 10)->default('order')->change();
        });
    }

    public function down(): void
    {
        CheckConstraint::drop('tenant_whatsapp', 'chk_wa_bot_type');
        CheckConstraint::in('tenant_whatsapp', 'bot_type', ['order', 'support', 'both'], 'chk_wa_bot_type');

        Schema::table('tenant_whatsapp', function (Blueprint $table) {
            $table->string('bot_type', 10)->default('both')->change();
        });
    }
};
