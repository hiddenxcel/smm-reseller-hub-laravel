<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own merchant credentials — how resellers pay US.
 *
 * The platform_settings migration argued credentials should stay in .env, and
 * the reasoning was sound: a key in the database is a key in every backup and
 * every dump, reachable behind a session cookie rather than behind server
 * access.
 *
 * What it did not price in is that nobody could set them. Editing .env needs
 * SSH, so in practice they were never filled in at all — and a billing system
 * with no gateway is a billing system that takes no money. A key that exists
 * but is exposed to a compromised owner session is worth more than a key that
 * does not exist.
 *
 * So: same table, tighter rules than the tenant equivalent.
 *
 *   - Encrypted at rest with APP_KEY, which is not in the database, so a dump
 *     on its own yields nothing.
 *   - Never sent to the browser. The screen shows "configured" or "not
 *     configured" and the last four characters, never the value.
 *   - Owner-only to read or write, and every change is audited.
 *   - .env still wins when it is set, so an operator who prefers the old
 *     arrangement keeps it and this table is simply unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_gateway_credentials', function (Blueprint $table) {
            // The gateway code from config/billing.php — snippe, nowpayments,
            // cryptomus, heleket. One row each, so the code is the key.
            $table->string('gateway', 30)->primary();

            $table->text('api_key_enc')->nullable();
            $table->text('webhook_secret_enc')->nullable();

            // Cryptomus and Heleket need a merchant UUID as well as a key.
            $table->text('extra_enc')->nullable();

            // Off by default: a gateway is stored before it is trusted, so
            // credentials can be saved and checked before resellers see it.
            $table->boolean('enabled')->default(false);

            $table->foreignId('superadmin_id')->nullable()->constrained('superadmins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_gateway_credentials');
    }
};
