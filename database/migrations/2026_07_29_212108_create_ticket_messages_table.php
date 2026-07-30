<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('sender', 10);
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index('ticket_id', 'idx_ticketmsg_ticket');
        });
        CheckConstraint::in('ticket_messages', 'sender', ['customer', 'ai', 'staff'], 'chk_ticketmsg_sender');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
