<?php

namespace App\Http\Controllers\Admin;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Services\Admin\AdminAudit;
use App\Services\Support\SupportTicketPresenter;
use App\Services\Support\SupportTickets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resellers asking the platform for help.
 *
 * The opposite of {@see TicketsController}, which is deliberately read-only:
 * that one holds a reseller's conversation with their own customer, and the
 * platform must not write into it. This queue is addressed to us, so replying
 * here is the entire point.
 *
 * Replies are audited. Support staff can read a reseller's account through
 * these threads, and the trail is what answers "who told them that?".
 */
class SupportController extends AdminController
{
    /** Newest first inside each bucket, but unanswered before answered. */
    public function index(Request $request): Response
    {
        $this->authorise('tickets.view');

        $status = $request->string('status')->toString();
        $status = in_array($status, SupportTicket::STATUSES, true) ? $status : null;

        $tickets = SupportTicket::withoutTenantScope()
            ->with('tenant:id,business_name,email')
            ->withCount('messages')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            // Waiting-on-us first regardless of age: a ticket nobody has
            // answered is the only kind that can go badly wrong on its own.
            ->orderByRaw("CASE WHEN status IN ('open', 'answered') THEN 0 ELSE 1 END")
            ->orderByDesc('last_reply_at')
            ->limit(200)
            ->get()
            ->map(fn (SupportTicket $ticket) => SupportTicketPresenter::adminRow($ticket))
            ->all();

        return Inertia::render('Admin/Support/Index', [
            'tickets' => $tickets,
            'counts' => SupportTicket::statusCounts(),
            'filters' => ['status' => $status],
            'canManage' => $this->can('tickets.manage'),
        ]);
    }

    public function show(int $ticket): Response
    {
        $this->authorise('tickets.view');

        $model = $this->find($ticket);

        return Inertia::render('Admin/Support/Show', [
            'ticket' => SupportTicketPresenter::adminRow($model),
            'messages' => $model->messages()
                ->with('admin:id,username,name')
                ->orderBy('id')
                ->get()
                ->map(fn (SupportTicketMessage $message) => SupportTicketPresenter::adminMessage($message))
                ->all(),
            'canManage' => $this->can('tickets.manage'),
            'priorities' => SupportTicket::PRIORITIES,
        ]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $this->authorise('tickets.manage');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'internal' => ['required', 'boolean'],
        ]);

        $model = $this->find($ticket);

        SupportTickets::for($model)->replyAsAdmin(
            (int) $this->admin()->id,
            $data['body'],
            $data['internal'],
        );

        // A note is not correspondence, and logging it as one would make the
        // trail claim we answered somebody we did not.
        AdminAudit::onTenant(
            $data['internal'] ? 'tickets.note' : 'tickets.reply',
            (int) $model->tenant_id,
            ['reference' => $model->reference],
        );

        return back()->with(
            'success',
            $data['internal'] ? 'Note added. The reseller cannot see it.' : 'Reply sent.',
        );
    }

    public function act(Request $request, int $ticket, string $action): RedirectResponse
    {
        $this->authorise('tickets.manage');

        $model = $this->find($ticket);
        $actions = SupportTickets::for($model);

        $message = match ($action) {
            'resolve' => $this->resolve($actions),
            'close' => $this->close($actions),
            'reopen' => $this->reopen($actions),
            'priority' => $this->setPriority($request, $actions),
        };

        AdminAudit::onTenant("tickets.{$action}", (int) $model->tenant_id, [
            'reference' => $model->reference,
        ]);

        return back()->with('success', $message);
    }

    private function resolve(SupportTickets $actions): string
    {
        $actions->resolve();

        return 'Marked resolved. The reseller can still reply to reopen it.';
    }

    private function close(SupportTickets $actions): string
    {
        $actions->close();

        return 'Closed. The reseller can no longer reply on this thread.';
    }

    private function reopen(SupportTickets $actions): string
    {
        $actions->reopen();

        return 'Reopened.';
    }

    private function setPriority(Request $request, SupportTickets $actions): string
    {
        $priority = $request->validate([
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
        ])['priority'];

        $actions->setPriority($priority);

        return "Priority set to {$priority}.";
    }

    /**
     * Route-model binding would apply the tenant scope, which is empty on the
     * superadmin guard — every lookup here would 404.
     */
    private function find(int $ticket): SupportTicket
    {
        return SupportTicket::withoutTenantScope()
            ->with('tenant:id,business_name,email')
            ->findOrFail($ticket);
    }
}
