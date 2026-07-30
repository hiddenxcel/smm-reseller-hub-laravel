<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_limits', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 100);
            $table->string('action', 50);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('window_start')->useCurrent();

            $table->index(['identifier', 'action'], 'idx_ratelimit_id_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limits');
    }
};
