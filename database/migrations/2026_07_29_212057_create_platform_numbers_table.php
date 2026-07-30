<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Your own rentable numbers (many countries), each with its own currency + cost.
        Schema::create('platform_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('display_number', 30)->unique();
            $table->string('phone_number_id', 50)->unique();
            $table->text('cloud_api_token_enc');
            $table->string('waba_id', 50)->nullable();
            $table->string('country', 60)->nullable();
            $table->string('country_code', 5)->nullable();
            $table->string('currency', 5)->default('USD');
            $table->decimal('monthly_cost', 12, 2)->default(0);
            $table->string('status', 20)->default('available');
            $table->timestamps();
        });
        CheckConstraint::in('platform_numbers', 'status', ['available', 'rented', 'suspended'], 'chk_platnum_status');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_numbers');
    }
};
