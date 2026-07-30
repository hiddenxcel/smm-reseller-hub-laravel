<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('customer_phone', 30);
            $table->string('direction', 5);
            $table->text('message')->nullable();
            $table->string('template_key', 50)->nullable();
            $table->string('bot_type', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at'], 'idx_botmsg_tenant_created');
            $table->index(['tenant_id', 'bot_type', 'created_at'], 'idx_botmsg_tenant_bot');
        });
        CheckConstraint::in('bot_messages', 'direction', ['in', 'out'], 'chk_botmsg_direction');
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_messages');
    }
};
