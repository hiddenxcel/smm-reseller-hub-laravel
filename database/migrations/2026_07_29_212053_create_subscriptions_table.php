<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MUHIMU: each service has its OWN subscription (sold a la carte).
        // Tri-state gate: pending -> sandbox|active -> expired|cancelled.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans');
            $table->string('service_key', 30);
            $table->string('status', 20)->default('pending');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'service_key', 'status'], 'idx_subs_tenant_service_status');
        });
        CheckConstraint::in('subscriptions', 'service_key', ['order_bot', 'support_bot', 'ai_tickets', 'ai_chat', 'number_rental'], 'chk_subs_service_key');
        CheckConstraint::in('subscriptions', 'status', ['pending', 'sandbox', 'active', 'expired', 'cancelled'], 'chk_subs_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
