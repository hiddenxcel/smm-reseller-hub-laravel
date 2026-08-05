<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every time an admin looked at the platform through a reseller's eyes.
     *
     * This is a table of its own rather than two rows in `activity_log` because
     * an impersonation is an interval, not an event: the question asked of it is
     * "was anyone inside this account at 14:40 on Tuesday", which a start row and
     * an end row scattered among thousands of other actions cannot answer without
     * a self-join. `ended_at` NULL means the session is still open.
     *
     * Sessions are read-only (see BlockDuringImpersonation), so this is a record
     * of who saw what, not of who changed what — which is precisely the record a
     * reseller is entitled to ask for.
     */
    public function up(): void
    {
        Schema::create('admin_impersonations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('superadmin_id')->constrained('superadmins')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();

            // "Is this admin already inside an account?" is asked on every
            // impersonation start, and "who has been in this tenant?" is the
            // reseller-facing question. Both are indexed.
            $table->index(['superadmin_id', 'ended_at'], 'idx_impersonation_open');
            $table->index(['tenant_id', 'started_at'], 'idx_impersonation_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_impersonations');
    }
};
