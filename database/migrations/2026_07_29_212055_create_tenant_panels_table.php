<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_panels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('panel_type', 30)->default('custom');
            $table->string('api_url', 255);
            $table->text('api_key_enc');
            $table->string('api_version', 5)->default('v2');
            $table->string('auth_method', 10)->default('param');
            $table->timestamp('last_checked_at')->nullable();
            $table->decimal('last_balance', 12, 2)->nullable();
            $table->string('balance_currency', 5)->nullable();
            $table->integer('services_count')->nullable();
            $table->string('status', 20)->default('inactive');
            $table->timestamps();

            $table->index('tenant_id', 'idx_panels_tenant');
        });
        CheckConstraint::in('tenant_panels', 'panel_type', ['perfectpanel', 'rentalpanel', 'custom'], 'chk_panels_type');
        CheckConstraint::in('tenant_panels', 'api_version', ['v1', 'v2'], 'chk_panels_api_version');
        CheckConstraint::in('tenant_panels', 'auth_method', ['header', 'param'], 'chk_panels_auth_method');
        CheckConstraint::in('tenant_panels', 'status', ['active', 'error', 'inactive'], 'chk_panels_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_panels');
    }
};
