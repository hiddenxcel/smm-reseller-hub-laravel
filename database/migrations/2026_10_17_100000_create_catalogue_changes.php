<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the panels changed under a reseller, waiting for their word.
 *
 * With auto-sync off the sync only notices: a cost that moved, or a service the
 * panel dropped, becomes a row here that the reseller allows or dismisses. With
 * it on the sync applies the change itself and leaves a row behind as the
 * record. The service name is copied onto the row because a removed service can
 * be deleted, and the history must still say what it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('auto_catalogue_sync')->default(false)->after('pricing_currency_changed_at');
        });

        Schema::create('catalogue_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('bot_services')->nullOnDelete();
            $table->unsignedBigInteger('panel_id')->nullable();
            $table->string('provider_service_id', 64)->nullable();
            $table->string('service_name', 255);
            // cost | removed
            $table->string('kind', 10);
            // pending | applied | dismissed
            $table->string('status', 10)->default('pending');
            $table->decimal('old_cost', 12, 4)->nullable();
            $table->decimal('new_cost', 12, 4)->nullable();
            $table->decimal('old_price', 12, 4)->nullable();
            $table->decimal('proposed_price', 12, 4)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'id'], 'idx_catalogue_changes_queue');
            $table->index(['service_id', 'kind', 'status'], 'idx_catalogue_changes_service');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_changes');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('auto_catalogue_sync');
        });
    }
};
