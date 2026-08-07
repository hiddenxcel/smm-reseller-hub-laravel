<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per API request, kept so a reseller can answer "it isn't working"
 * without guessing.
 *
 * That question is the entire reason this table exists: the caller is someone
 * else's code, on someone else's server, and when it breaks the reseller has
 * nothing to look at. The failure reason is recorded verbatim, so "invalid
 * service id" and "insufficient funds" are distinguishable after the fact.
 *
 * api_key_id is nullable and tenant_id is not tied to it, because the request
 * that most needs logging is the one whose key did not resolve — a wrong or
 * revoked key has no row to point at, and dropping those would hide exactly
 * the case someone is debugging. Such rows carry a null tenant and are only
 * visible to the super-admin console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();

            $table->string('action', 30)->nullable();
            $table->string('ip', 45)->nullable();

            // False plus `error` is the whole story of a failed call. Kept as
            // a boolean rather than an HTTP status because the SMM API answers
            // 200 for everything, including its own errors.
            $table->boolean('ok')->default(false);
            $table->string('error', 255)->nullable();

            // What the call did, when it did anything: the order it created,
            // the ids it asked about. Not the full request — that would store
            // the key.
            $table->jsonb('details')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // The logs screen reads one tenant's rows newest-first, and the
            // pruning job reads by age across all of them.
            $table->index(['tenant_id', 'created_at'], 'idx_apilogs_tenant_created');
            $table->index('created_at', 'idx_apilogs_created');
            $table->index(['api_key_id', 'created_at'], 'idx_apilogs_key_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
