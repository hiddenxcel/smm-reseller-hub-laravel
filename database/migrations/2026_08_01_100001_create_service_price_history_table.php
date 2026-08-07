<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every price change, and what caused it.
 *
 * A reseller's margin is the business, and prices move for four different
 * reasons — someone edited one, a bulk tool swept a category, a markup rule
 * fired during a sync, or the panel's own cost moved underneath them. Without
 * this, "why is this service suddenly losing money?" has no answer.
 *
 * Both the old and the new value are stored rather than a delta: a delta is
 * only meaningful if you have every row in between, and rows get pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('bot_services')->cascadeOnDelete();

            $table->string('reason', 20);

            $table->decimal('old_price', 12, 4)->nullable();
            $table->decimal('new_price', 12, 4);
            $table->decimal('old_cost', 12, 4)->nullable();
            $table->decimal('new_cost', 12, 4)->nullable();

            // Free text describing the sweep or rule that did it, so a bulk
            // change can be recognised without joining anything.
            $table->string('note', 190)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['service_id', 'created_at'], 'idx_svcprice_service');
            $table->index(['tenant_id', 'created_at'], 'idx_svcprice_tenant');
        });

        CheckConstraint::in(
            'service_price_history',
            'reason',
            ['manual', 'bulk', 'rule', 'sync', 'import'],
            'chk_svcprice_reason',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_price_history');
    }
};
