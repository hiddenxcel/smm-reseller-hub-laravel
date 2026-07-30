<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bot state machine: (tenant, phone, bot_type) -> state + JSON context.
        Schema::create('bot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('customer_phone', 30);
            $table->string('bot_type', 10);
            $table->string('state', 50)->default('IDLE');
            $table->jsonb('context')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'customer_phone', 'bot_type'], 'uq_conv_tenant_phone_type');
        });
        CheckConstraint::in('bot_conversations', 'bot_type', ['order', 'support'], 'chk_conv_bot_type');
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_conversations');
    }
};
