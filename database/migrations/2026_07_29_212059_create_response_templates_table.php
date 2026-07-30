<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tenant_id NULL = platform default template.
        Schema::create('response_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('template_key', 50);
            $table->string('lang', 5)->default('en');
            $table->text('content');
            $table->boolean('is_default')->default(false);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'template_key', 'lang'], 'uq_template_tenant_key_lang');
        });
        CheckConstraint::in('response_templates', 'lang', ['en', 'fr', 'sw'], 'chk_template_lang');
    }

    public function down(): void
    {
        Schema::dropIfExists('response_templates');
    }
};
