<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('bot_type', 10);
            $table->jsonb('settings')->nullable();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'bot_type'], 'uq_botsettings_tenant_type');
        });
        CheckConstraint::in('tenant_bot_settings', 'bot_type', ['order', 'support'], 'chk_botsettings_type');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_bot_settings');
    }
};
