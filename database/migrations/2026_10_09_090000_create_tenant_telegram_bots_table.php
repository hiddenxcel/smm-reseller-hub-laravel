<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reseller's Telegram bot, made with @BotFather.
 *
 * public_id is the unguessable part of the webhook URL (it finds the row);
 * webhook_secret is what Telegram echoes back in a header, so a stranger who
 * learned the URL still cannot post to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bot_type', 20);
            $table->text('token_enc');
            $table->unsignedBigInteger('telegram_bot_id');
            $table->string('bot_username', 64);
            $table->string('public_id', 40)->unique();
            $table->string('webhook_secret', 64);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'bot_type']);
            $table->unique('telegram_bot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_telegram_bots');
    }
};
