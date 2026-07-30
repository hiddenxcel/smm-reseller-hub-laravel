<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wallet-based Order Bot customers (ported from kuzapanel-bot, multi-tenant).
        Schema::create('bot_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('phone', 30);
            $table->string('name', 150)->nullable();
            $table->string('lang', 5)->default('en');
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->string('referral_code', 12)->nullable();
            $table->foreignId('referred_by')->nullable()->constrained('bot_customers')->nullOnDelete();
            $table->decimal('referral_earnings', 12, 2)->default(0);
            $table->boolean('first_deposit_done')->default(false);
            $table->string('last_payment_phone', 30)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone'], 'uq_botcust_tenant_phone');
            $table->unique(['tenant_id', 'referral_code'], 'uq_botcust_tenant_refcode');
            $table->index('tenant_id', 'idx_botcust_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_customers');
    }
};
