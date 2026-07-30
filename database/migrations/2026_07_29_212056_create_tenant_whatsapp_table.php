<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // phone_number_id UNIQUE = webhook router key (Meta payload -> tenant).
        Schema::create('tenant_whatsapp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('source', 10)->default('own');
            $table->text('cloud_api_token_enc')->nullable();
            $table->string('phone_number_id', 50)->unique();
            $table->string('waba_id', 50)->nullable();
            $table->string('verify_token', 100)->nullable();
            $table->string('display_number', 30)->nullable();
            $table->string('status', 20)->default('inactive');
            $table->string('bot_type', 10)->default('both');
            $table->timestamps();

            $table->index('tenant_id', 'idx_wa_tenant');
        });
        CheckConstraint::in('tenant_whatsapp', 'source', ['own', 'rented'], 'chk_wa_source');
        CheckConstraint::in('tenant_whatsapp', 'status', ['active', 'error', 'inactive'], 'chk_wa_status');
        CheckConstraint::in('tenant_whatsapp', 'bot_type', ['order', 'support', 'both'], 'chk_wa_bot_type');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_whatsapp');
    }
};
