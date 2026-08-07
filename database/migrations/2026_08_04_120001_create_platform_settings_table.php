<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-wide settings an admin may change without a deploy.
     *
     * Deliberately NOT where gateway credentials live. Those stay in .env and
     * config/services.php: a key in the database is a key in every backup, in
     * every database dump a developer pulls down, and behind a session cookie
     * rather than behind server access. The Settings screen reports whether
     * each gateway is configured; changing one is still a deploy.
     *
     * What belongs here is the copy and the small numbers: company name,
     * support address, the referral percentage. Things a business decision
     * changes, not things a compromise would exploit.
     *
     * Key-value rather than columns, because the set grows and a migration per
     * setting is friction with no payoff at this size. `value` is JSON so a
     * setting can hold a number, a flag, or a string without three columns.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->jsonb('value')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreignId('superadmin_id')->nullable()->constrained('superadmins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
