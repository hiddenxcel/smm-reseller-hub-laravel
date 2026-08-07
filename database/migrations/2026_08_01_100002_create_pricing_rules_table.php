<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing markup rules: "Instagram is cost +30%, TikTok is cost +$0.50".
 *
 * The point is the sync. A panel's costs move without warning, and a reseller
 * with a thousand services cannot re-price them by hand — so a rule says how
 * much margin they want and the sync keeps it true.
 *
 * Rules are ordered and the first match wins, rather than every match applying
 * in turn: stacking two markups on one service is never what someone means,
 * and the resulting price would depend on evaluation order in a way nobody
 * could read off the screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name', 100);

            // What it applies to. Null on both means "everything", which is how
            // a catch-all floor rule is expressed.
            $table->string('platform', 50)->nullable();
            $table->foreignId('panel_id')->nullable()->constrained('tenant_panels')->nullOnDelete();

            // How the price is derived from cost.
            $table->string('mode', 20);
            $table->decimal('amount', 12, 4);

            // Guard rails, applied after the mode. A percentage markup on a
            // very cheap service can produce a margin of a fraction of a cent;
            // these are how a reseller says "never less than this".
            $table->decimal('min_profit', 12, 4)->nullable();
            $table->decimal('max_profit', 12, 4)->nullable();

            // Round the result to something a customer reads without flinching.
            $table->decimal('round_to', 12, 4)->nullable();

            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'active', 'sort_order'], 'idx_pricerule_tenant');
        });

        CheckConstraint::in(
            'pricing_rules',
            'mode',
            ['percent', 'fixed', 'multiplier'],
            'chk_pricerule_mode',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
