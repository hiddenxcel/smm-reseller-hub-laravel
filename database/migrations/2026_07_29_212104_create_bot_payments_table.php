<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 20)->default('wallet_topup');
            $table->foreignId('customer_id')->nullable()->constrained('bot_customers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('bot_orders')->nullOnDelete();
            $table->string('gateway', 50);
            $table->string('transaction_ref', 120)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            // Binance "internal transfer" flow: the payer-reported Binance Order ID.
            // UNIQUE per tenant (each tenant verifies against its own Binance account).
            $table->string('binance_order_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'transaction_ref'], 'uq_botpay_tenant_ref');
            $table->unique(['tenant_id', 'binance_order_id'], 'uq_botpay_binance_order');
            $table->index(['tenant_id', 'status'], 'idx_botpay_tenant_status');
            $table->index('customer_id', 'idx_botpay_customer');
        });
        CheckConstraint::in('bot_payments', 'type', ['wallet_topup', 'order_payment'], 'chk_botpay_type');
        CheckConstraint::in('bot_payments', 'status', ['pending', 'success', 'failed'], 'chk_botpay_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_payments');
    }
};
