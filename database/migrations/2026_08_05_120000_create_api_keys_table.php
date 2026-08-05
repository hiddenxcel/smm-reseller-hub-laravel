<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keys that let a reseller's customer order without WhatsApp.
 *
 * A key belongs to a bot_customer, not to the tenant alone: the customer is
 * who gets charged, so an order placed over the API debits the same wallet
 * the bot would have debited. One customer may hold several keys (a live one
 * and a staging one, say), which is also how a key is rotated without
 * downtime — issue the second, move traffic, revoke the first.
 *
 * Only the hash is stored. The plaintext key is shown once, at creation, and
 * cannot be recovered afterwards; a reseller who loses it issues a new one.
 * The lookup is by hash, so it is a plain sha256 rather than a password hash
 * — bcrypt cannot be searched for, and the key is 48 random bytes rather
 * than something guessable, which is what the slow hash would have been
 * protecting against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('bot_customers')->cascadeOnDelete();

            // Unique across the platform, not per tenant: the key is the only
            // thing an API request carries, so it has to identify the tenant
            // by itself.
            $table->string('key_hash', 64)->unique();

            // The first few characters of the plaintext, kept so the screen
            // can show which key a log line belongs to without revealing it.
            $table->string('key_prefix', 12);

            $table->string('label', 60)->nullable();
            $table->string('status', 20)->default('active');

            // Requests per minute. Null means the platform default applies,
            // so raising the default later lifts every key that never asked
            // for a specific number.
            $table->unsignedSmallInteger('rate_limit')->nullable();

            // Addresses allowed to use this key. Null means anywhere; an empty
            // list would mean nowhere, which is a footgun, so the screen never
            // writes one.
            $table->jsonb('ip_allowlist')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'idx_apikeys_tenant_status');
            $table->index('customer_id', 'idx_apikeys_customer');
        });
        CheckConstraint::in('api_keys', 'status', ['active', 'revoked'], 'chk_apikeys_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
