<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notices the platform shows its resellers: maintenance windows, new
     * features, price changes.
     *
     * Deliberately a banner on the dashboard rather than a message sent through
     * anyone's WhatsApp number. A reseller's number is their business asset and
     * their Meta rate limit; the platform using it to talk to them would spend
     * a template they need for their own customers.
     *
     * `published_at` NULL means a draft — written but not shown to anybody.
     * `expires_at` NULL means it stays until it is unpublished, which suits the
     * "new feature" case; a maintenance notice sets one so it disappears on its
     * own rather than relying on someone remembering.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('superadmin_id')->nullable()->constrained('superadmins')->nullOnDelete();
            $table->string('title', 150);
            $table->text('body');
            $table->string('level', 20)->default('info');
            $table->boolean('dismissible')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The dashboard asks "what is live right now?" on every page load,
            // so that is the query the index serves.
            $table->index(['published_at', 'expires_at'], 'idx_announcements_live');
        });

        CheckConstraint::in('announcements', 'level', ['info', 'warning', 'critical'], 'chk_announcements_level');
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
