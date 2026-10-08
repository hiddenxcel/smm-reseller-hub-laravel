<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A panel's Admin API, kept apart from the reseller API key.
 *
 * The reseller key only ever sees the orders of the account that owns it, so
 * it cannot act for the panel's own customers. The Admin API can look a
 * customer up, put a code in their account, and read or refill their orders.
 * Its key is a staff credential, so it is stored encrypted and never sent back
 * to the browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_panels', function (Blueprint $table) {
            $table->string('admin_api_url', 255)->nullable();
            $table->text('admin_api_key_enc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_panels', function (Blueprint $table) {
            $table->dropColumn(['admin_api_url', 'admin_api_key_enc']);
        });
    }
};
