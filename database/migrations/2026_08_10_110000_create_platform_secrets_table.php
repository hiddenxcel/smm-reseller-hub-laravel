<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform secrets that are not gateway credentials.
 *
 * The same argument as platform_gateway_credentials, for the same reason. The
 * platform_settings migration made the case that credentials belong in .env —
 * a key in the database is a key in every backup and every dump — and that
 * reasoning still holds. What it did not price in is that editing .env needs
 * SSH, which in practice means the key is never set at all.
 *
 * For the website assistant that is not an abstract cost: with no key the
 * widget does not render, so the whole feature is off until somebody opens a
 * terminal. A key exposed to a compromised owner session is worth more than a
 * feature nobody can switch on.
 *
 * Same protections as the gateway table:
 *
 *   - Encrypted at rest with APP_KEY, which is not in the database, so a dump
 *     on its own yields nothing.
 *   - Never sent to the browser — the screen shows whether it is set and the
 *     last four characters, never the value.
 *   - Owner-only to read or write, and every change is audited.
 *   - .env still wins when set, so an operator who prefers keys on disk keeps
 *     that arrangement and this table simply goes unused.
 *
 * Separate from platform_gateway_credentials rather than squeezed into it: a
 * gateway row is four fields with a fixed meaning, and a secret is one value
 * with a name. Sharing the table would mean a column that is null for every
 * row of one kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_secrets', function (Blueprint $table) {
            // The secret's name — see PlatformSecret::KEYS.
            $table->string('key', 60)->primary();

            $table->text('value_enc')->nullable();

            // Off by default: a secret is stored before it is trusted, so it
            // can be saved and checked before anything starts using it.
            $table->boolean('enabled')->default(false);

            $table->foreignId('superadmin_id')->nullable()->constrained('superadmins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_secrets');
    }
};
