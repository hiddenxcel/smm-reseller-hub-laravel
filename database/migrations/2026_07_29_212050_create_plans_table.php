<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A la carte pricing catalog: one plan per service. Bundles = future presets.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('service_key', 30);
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->decimal('price_yearly', 12, 2)->default(0);
            $table->string('currency', 5)->default('USD');
            $table->integer('max_panels')->default(1);
            $table->integer('max_numbers')->default(1);
            $table->integer('max_orders_monthly')->default(1000);
            $table->integer('max_messages_monthly')->default(5000);
            $table->integer('max_refills_monthly')->default(100);
            $table->string('status', 20)->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        CheckConstraint::in('plans', 'service_key', ['order_bot', 'support_bot', 'ai_tickets', 'ai_chat', 'number_rental'], 'chk_plans_service_key');
        CheckConstraint::in('plans', 'status', ['active', 'inactive'], 'chk_plans_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
