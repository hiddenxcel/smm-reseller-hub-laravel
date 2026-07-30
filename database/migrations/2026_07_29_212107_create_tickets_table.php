<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SMMGen-style structured tickets: Category (AI/Human) -> Subcategory -> Order ID.
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('customer_identifier', 190)->nullable();
            $table->string('category', 10)->default('ai');
            $table->string('subcategory', 40)->nullable();
            $table->string('order_ref', 60)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('normal');
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'idx_tickets_tenant_status');
        });
        CheckConstraint::in('tickets', 'category', ['ai', 'human'], 'chk_tickets_category');
        CheckConstraint::in('tickets', 'status', ['open', 'pending', 'resolved', 'closed'], 'chk_tickets_status');
        CheckConstraint::in('tickets', 'priority', ['low', 'normal', 'high'], 'chk_tickets_priority');
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
