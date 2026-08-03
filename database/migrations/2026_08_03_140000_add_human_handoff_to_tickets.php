<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who is answering this customer — the bot, or a person.
     *
     * The support bot drives a numbered Quick Menu, so a staff reply typed
     * into the inbox while the bot is mid-question leaves the customer
     * answering two voices at once. Option 5, "Talk to a Human Agent", is the
     * customer asking for exactly that switch, so it is what claims the
     * conversation: from then on the bot stays silent until staff hand it back.
     *
     * This lives on the ticket rather than on `bot_conversations`, which has a
     * 30-minute TTL and a cleanup job behind it. A handoff that expired on its
     * own would put the bot back mid-conversation without anybody deciding to,
     * which is the failure this column exists to prevent.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Set while a person owns the conversation; NULL means the bot does.
            $table->timestamp('handed_over_at')->nullable()->after('priority');

            // Last inbound message, for the WhatsApp 24-hour reply window.
            $table->timestamp('last_customer_at')->nullable()->after('handed_over_at');
        });

        // The inbox asks one question constantly: does this phone have a live
        // handoff? Answering it from the index keeps that off a table scan.
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['tenant_id', 'customer_identifier', 'handed_over_at'], 'idx_tickets_handoff');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_handoff');
            $table->dropColumn(['handed_over_at', 'last_customer_at']);
        });
    }
};
