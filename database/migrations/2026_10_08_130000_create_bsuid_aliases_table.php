<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp users who hide their phone number.
 *
 * Meta identifies such a person to a business only by a business-scoped user
 * ID (a BSUID), up to 128 characters, and the whole app keys customers,
 * conversations, orders and payments on a short "phone" string. Rather than
 * widen every one of those columns, a BSUID is given a short stable alias that
 * stands in for the phone everywhere, and is turned back into the BSUID only
 * at the moment a message is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bsuid_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bsuid', 200);
            $table->string('alias', 30);
            $table->string('username', 100)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'bsuid'], 'uq_bsuid_tenant');
            $table->unique(['tenant_id', 'alias'], 'uq_bsuid_alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bsuid_aliases');
    }
};
