<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reseller asking the platform for help.
     *
     * Deliberately not the `tickets` table. That one is a customer talking to a
     * reseller over WhatsApp: it is tenant-scoped, keyed by phone number, and
     * carries Meta's 24-hour reply window. This one is a reseller talking to us
     * — it has an authenticated account behind it, no phone number, no reply
     * window, and an admin rather than a bot on the other end. The two share a
     * word and nothing else, and merging them would put a customer's phone
     * number and a billing complaint in the same query.
     *
     * `reference` is what a reseller quotes at us. An auto-increment id would do
     * the job but leaks how many tickets the platform has ever had, so the
     * number is generated per row.
     *
     * There is no `assigned_to`: with a handful of admins, a queue everyone sees
     * beats a queue where a ticket can sit assigned to somebody on leave.
     */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 150);
            $table->string('category', 30);
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('open');

            // Who spoke last, so the queue can show what is waiting on us. Kept
            // as a column rather than derived from the last message: the list
            // screen would otherwise need a subquery per row.
            $table->string('last_reply_by', 20)->default('tenant');
            $table->timestamp('last_reply_at')->nullable();

            // Stamped when an admin first opens it — the only response time
            // worth reporting is the first one.
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // The two queries the screens actually run: a reseller's own list,
            // newest first, and the admin queue filtered by status.
            $table->index(['tenant_id', 'status', 'id'], 'idx_support_tickets_tenant');
            $table->index(['status', 'priority', 'last_reply_at'], 'idx_support_tickets_queue');
        });

        CheckConstraint::in('support_tickets', 'status', ['open', 'pending', 'answered', 'resolved', 'closed'], 'chk_support_tickets_status');
        CheckConstraint::in('support_tickets', 'priority', ['low', 'normal', 'high', 'critical'], 'chk_support_tickets_priority');
        CheckConstraint::in('support_tickets', 'last_reply_by', ['tenant', 'admin'], 'chk_support_tickets_last_reply_by');

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();

            // Exactly one of these is set. The author is not a single polymorphic
            // pair because the two sides are read differently: a reseller sees
            // "Support" for every admin reply, while the console wants to know
            // which admin wrote it.
            $table->foreignId('superadmin_id')->nullable()->constrained('superadmins')->nullOnDelete();
            $table->string('author', 20);
            $table->text('body');

            // A note is an admin talking to other admins on the ticket. The
            // reseller's screen filters these out; keeping them on the ticket
            // rather than in a separate table means the thread reads in order.
            $table->boolean('internal')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['support_ticket_id', 'id'], 'idx_support_messages_thread');
        });

        CheckConstraint::in('support_ticket_messages', 'author', ['tenant', 'admin'], 'chk_support_messages_author');
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
