<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 100);
            $table->jsonb('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_type', 'actor_id'], 'idx_activity_actor');
            $table->index('created_at', 'idx_activity_created');
        });
        CheckConstraint::in('activity_log', 'actor_type', ['tenant', 'superadmin', 'system'], 'chk_activity_actor_type');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
