<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('panel_id')->nullable()->constrained('tenant_panels')->nullOnDelete();
            $table->string('provider_order_id', 50)->nullable();
            $table->string('customer_phone', 30);
            $table->foreignId('customer_id')->nullable()->constrained('bot_customers')->nullOnDelete();
            $table->string('service_id', 50)->nullable();
            $table->string('service_name', 190)->nullable();
            $table->string('link', 255)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('payment_status', 20)->default('pending');
            $table->string('paid_from', 10)->nullable();
            $table->string('order_error', 255)->nullable();
            $table->decimal('charge', 12, 4)->nullable();
            $table->string('status', 30)->nullable();
            $table->string('refill_status', 30)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'idx_botorders_tenant_status');
            $table->index('provider_order_id', 'idx_botorders_provider');
            $table->index('customer_id', 'idx_botorders_customer');
        });
        CheckConstraint::in('bot_orders', 'payment_status', ['pending', 'paid', 'failed'], 'chk_botorders_payment_status');
        CheckConstraint::in('bot_orders', 'paid_from', ['wallet', 'gateway'], 'chk_botorders_paid_from');
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_orders');
    }
};
