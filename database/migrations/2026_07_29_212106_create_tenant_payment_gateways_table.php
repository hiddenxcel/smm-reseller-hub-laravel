<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant's OWN gateway (their customers pay them). Kept wide: tenant picks any gateway.
        Schema::create('tenant_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('gateway', 50);
            $table->text('api_key_enc')->nullable();
            $table->text('webhook_secret_enc')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('tenant_id', 'idx_tpg_tenant');
        });
        CheckConstraint::in('tenant_payment_gateways', 'status', ['active', 'inactive'], 'chk_tpg_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_gateways');
    }
};
