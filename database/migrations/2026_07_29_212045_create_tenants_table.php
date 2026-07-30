<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('business_name', 150);
            $table->string('email', 190)->unique();
            $table->string('phone', 30)->nullable();
            $table->string('password_hash');
            $table->string('status', 20)->default('active');
            $table->string('lang', 5)->default('en');
            $table->string('referral_code', 12)->nullable()->unique();
            $table->foreignId('referred_by')->nullable()->constrained('tenants')->nullOnDelete();
            $table->decimal('referral_credit', 12, 2)->default(0);
            $table->boolean('first_payment_done')->default(false);
            $table->timestamps();

            $table->index('referred_by');
        });
        CheckConstraint::in('tenants', 'status', ['active', 'suspended'], 'chk_tenants_status');
        CheckConstraint::in('tenants', 'lang', ['en', 'fr', 'sw'], 'chk_tenants_lang');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
