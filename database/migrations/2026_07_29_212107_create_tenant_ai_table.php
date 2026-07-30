<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique('uq_ai_tenant')->constrained('tenants')->cascadeOnDelete();
            $table->text('deepseek_api_key_enc')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        CheckConstraint::in('tenant_ai', 'status', ['active', 'inactive'], 'chk_ai_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai');
    }
};
