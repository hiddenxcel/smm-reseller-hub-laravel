<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            // Unique across every team, and kept clear of tenants.email in the
            // application: one address must mean one person at the login form.
            $table->string('email', 190)->unique();
            $table->string('role', 20);
            // Null until the invite is accepted.
            $table->string('password_hash')->nullable();
            // Only the hash of the invite token is stored, so a database leak
            // does not hand out working invite links.
            $table->string('invite_token_hash', 64)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'idx_team_members_tenant');
        });

        CheckConstraint::in('team_members', 'role', ['admin', 'support', 'viewer'], 'chk_team_members_role');
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
