<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Addresses refused at the door.
     *
     * Deliberately a small, manual list rather than an automatic ban system.
     * Automatic blocking on failed logins is how a shared office IP locks out
     * a paying reseller at 2am with nobody watching — so an admin decides, and
     * the reason is recorded next to the decision.
     *
     * `expires_at` NULL is permanent. A temporary block is the common case (a
     * burst of credential stuffing dies down), and one that lifts itself is one
     * fewer thing to remember.
     */
    public function up(): void
    {
        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->string('reason', 255)->nullable();
            $table->foreignId('superadmin_id')->nullable()->constrained('superadmins')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Checked on every request that reaches the guard, so the lookup
            // that matters is "is this address blocked right now".
            $table->index(['ip', 'expires_at'], 'idx_blocked_ips_live');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
    }
};
