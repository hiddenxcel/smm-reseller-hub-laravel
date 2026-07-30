<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // panel_id NULL = tenant-wide rule.
        Schema::create('guarantee_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('panel_id')->nullable()->constrained('tenant_panels')->cascadeOnDelete();
            $table->string('rule_type', 20);
            $table->string('keyword', 100);
            $table->integer('refill_days')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('tenant_id', 'idx_rules_tenant');
        });
        CheckConstraint::in('guarantee_rules', 'rule_type', ['no_guarantee', 'guarantee'], 'chk_rules_type');
        CheckConstraint::in('guarantee_rules', 'status', ['active', 'inactive'], 'chk_rules_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantee_rules');
    }
};
