<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SaaS billing (tenant -> HiddenXcel). transaction_ref UNIQUE = replay protection.
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions');
            $table->string('gateway', 30);
            $table->string('transaction_ref', 100)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 5)->default('USD');
            $table->unsignedInteger('months')->default(1);
            $table->string('status', 20)->default('pending');
            $table->text('raw_response')->nullable();
            // Binance "internal transfer" flow: the payer-reported Binance Order ID we
            // verified against. UNIQUE = one Binance order can fund at most one payment.
            $table->string('binance_order_id', 64)->nullable()->unique('uq_subpay_binance_order');
            $table->timestamps();
        });
        CheckConstraint::in('subscription_payments', 'gateway', ['nowpayments', 'binance', 'snippe', 'cryptomus', 'heleket'], 'chk_subpay_gateway');
        CheckConstraint::in('subscription_payments', 'status', ['pending', 'success', 'failed'], 'chk_subpay_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
