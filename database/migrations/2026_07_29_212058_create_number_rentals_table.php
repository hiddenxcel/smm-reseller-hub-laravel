<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('platform_number_id')->constrained('platform_numbers');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('tenant_id', 'idx_rentals_tenant');
        });
        CheckConstraint::in('number_rentals', 'status', ['active', 'expired', 'revoked'], 'chk_rentals_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('number_rentals');
    }
};
