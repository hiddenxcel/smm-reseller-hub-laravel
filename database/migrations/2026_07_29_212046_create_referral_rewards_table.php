<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Referral program: referrer earns credit when a referred tenant first pays.
        // UNIQUE(referred_id) = one reward per referred tenant, ever.
        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('referred_id')->unique('uq_reward_referred')->constrained('tenants')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 5)->default('USD');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('referrer_id', 'idx_reward_referrer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
    }
};
