<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The website assistant: what it may say, and what it has been asked.
 *
 * Platform-owned, not tenant-scoped. A visitor asking "what is Order Bot?" has
 * no account and belongs to no reseller — they are deciding whether to become
 * one. Giving these tables a tenant_id would be a column nothing could ever
 * fill.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Everything the assistant is allowed to claim, in the platform's own
        // words. The point of keeping it in a table rather than in the prompt
        // is that a question we answered badly on Monday can be answered
        // properly on Tuesday without a deploy.
        Schema::create('assistant_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 40);
            $table->string('question', 255);

            // The same question as a Kiswahili reader would ask it. Shown as a
            // follow-up chip, and matched on — somebody typing "bei ni ngapi"
            // should reach the answer about price without the English wording
            // ever having to appear in front of them.
            $table->string('question_sw', 255)->nullable();

            $table->text('answer');

            // Written Kiswahili rather than translated on the fly: the answers
            // name products and prices, and a model translating those is a
            // model given permission to reword them.
            $table->text('answer_sw')->nullable();

            // The whole reason a marketing assistant beats a docs search. An
            // answer that ends in "here is where to buy it" converts; one that
            // ends in a full stop does not.
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url', 120)->nullable();

            // Space-separated, for the words people actually type. Somebody
            // asking about "namba tayari" and somebody asking about "rented
            // number" want the same paragraph.
            $table->text('keywords')->nullable();

            $table->string('status', 20)->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order'], 'idx_assistant_knowledge_live');
        });

        CheckConstraint::in('assistant_knowledge', 'status', ['active', 'hidden'], 'chk_assistant_knowledge_status');

        // One visitor's conversation. Keyed by a token the browser keeps in
        // sessionStorage, not by a login — there isn't one.
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_token')->unique();

            // Where they opened the chat. A question asked from /pricing means
            // something different from the same words typed on the blog, and
            // it is the single most useful column when reading these back.
            $table->string('page', 200)->nullable();
            $table->string('locale', 5)->default('en');
            $table->unsignedInteger('messages_count')->default(0);

            // Set the moment they ask for a human. The gap between this and
            // messages_count is the honest measure of whether the assistant is
            // working.
            $table->timestamp('escalated_at')->nullable();

            $table->string('lead_name', 120)->nullable();
            $table->string('lead_phone', 32)->nullable();
            $table->text('lead_message')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('created_at', 'idx_assistant_conversations_created');
            $table->index('escalated_at', 'idx_assistant_conversations_escalated');
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');

            // knowledge | ai | fallback, null on a visitor's own message.
            //
            // This is what the console reports on: a question that came back
            // `fallback` is a knowledge row somebody still has to write, and
            // one answered from `knowledge` cost nothing to answer.
            $table->string('answered_by', 20)->nullable();
            $table->foreignId('matched_knowledge_id')->nullable()->constrained('assistant_knowledge')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'id'], 'idx_assistant_messages_thread');
            $table->index('answered_by', 'idx_assistant_messages_answered_by');
        });

        CheckConstraint::in('assistant_messages', 'role', ['user', 'assistant'], 'chk_assistant_messages_role');
        CheckConstraint::in('assistant_messages', 'answered_by', ['knowledge', 'ai', 'fallback'], 'chk_assistant_messages_answered_by');
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
        Schema::dropIfExists('assistant_knowledge');
    }
};
