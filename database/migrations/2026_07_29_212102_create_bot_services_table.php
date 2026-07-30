<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('panel_id')->nullable()->constrained('tenant_panels')->nullOnDelete();
            $table->string('provider_service_id', 50);
            $table->string('platform', 50);
            $table->string('category', 80)->nullable();
            $table->string('name', 190);
            $table->string('unit_label', 50)->default('Followers');
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('my_price', 12, 4);
            $table->unsignedInteger('min_quantity')->default(1);
            $table->unsignedInteger('max_quantity')->default(100000);
            $table->text('link_instructions')->nullable();
            $table->string('status', 20)->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'idx_botsvc_tenant_status');
            $table->index(['tenant_id', 'platform'], 'idx_botsvc_tenant_platform');
            $table->index(['tenant_id', 'platform', 'category'], 'idx_botsvc_tenant_cat');
        });
        CheckConstraint::in('bot_services', 'status', ['active', 'inactive'], 'chk_botsvc_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_services');
    }
};
