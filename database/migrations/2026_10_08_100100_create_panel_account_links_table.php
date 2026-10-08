<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which WhatsApp number belongs to which account on a reseller's panel.
 *
 * A number is linked only after its owner types back a code that was put in
 * the panel account's own Tickets, so the link is proof of control of the
 * account. One row per (panel, number); a code waiting to be confirmed is kept
 * apart from the account already linked, so asking to link a different account
 * never loses the current link until the new one is proven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('panel_id');
            $table->string('customer_phone', 30);

            // The proven link.
            $table->unsignedBigInteger('panel_user_id')->nullable();
            $table->string('panel_username', 100)->nullable();
            $table->timestamp('verified_at')->nullable();

            // A code waiting to be typed back. Only a keyed hash is kept.
            $table->unsignedBigInteger('pending_user_id')->nullable();
            $table->string('pending_username', 100)->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'panel_id', 'customer_phone'], 'uq_panel_link_phone');
            $table->index(['tenant_id', 'panel_id', 'panel_user_id'], 'idx_panel_link_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_account_links');
    }
};
