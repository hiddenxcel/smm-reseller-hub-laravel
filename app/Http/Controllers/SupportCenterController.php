<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Services\Support\SupportTicketPresenter;
use App\Services\Support\SupportTickets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Where a reseller goes when they are stuck.
 *
 * The direction nothing else in the app covers: every other ticket screen is a
 * reseller answering their own customers. This is the reseller asking us, and
 * before this existed the only route was a WhatsApp message to whoever they had
 * a number for — unlogged, unassignable and invisible to anyone else on the
 * team.
 *
 * The contact details shown alongside come from PlatformSetting so support can
 * be pointed somewhere new without a deploy.
 */
class SupportCenterController extends Controller
{
    /**
     * The landing screen: how to reach us, and what you have already asked.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Help/SupportCenter', [
            'tickets' => $this->tickets(),
            'contacts' => $this->contacts(),
            'categories' => SupportTicket::CATEGORIES,
            'priorities' => SupportTicket::PRIORITIES,
        ]);
    }

    /**
     * One thread.
     *
     * Internal notes are excluded in the query rather than in the component —
     * see SupportTicketMessage::scopeVisibleToTenant.
     */
    public function show(Request $request, SupportTicket $ticket): Response
    {
        $this->authoriseTicket($request, $ticket);

        return Inertia::render('Help/SupportTicket', [
            'ticket' => SupportTicketPresenter::row($ticket),
            'messages' => $ticket->messages()
                ->visibleToTenant()
                ->orderBy('id')
                ->get()
                ->map(fn (SupportTicketMessage $message) => SupportTicketPresenter::message($message))
                ->all(),
            'canReply' => $ticket->acceptsReply(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = SupportTickets::open($request->user(), $data);

        return redirect()
            ->route('help.tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->reference} is open. We will reply here.");
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authoriseTicket($request, $ticket);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        // A closed ticket is locked on purpose: it is how a thread that went
        // somewhere it should not have stays ended.
        if (! $ticket->acceptsReply()) {
            return back()->with('error', 'This ticket is closed. Please open a new one.');
        }

        SupportTickets::for($ticket)->replyAsTenant($data['body']);

        return back()->with('success', 'Reply sent.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tickets(): array
    {
        return SupportTicket::query()
            ->withCount('messages')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SupportTicket $ticket) => SupportTicketPresenter::row($ticket))
            ->all();
    }

    /**
     * The ways to reach us that are actually configured.
     *
     * Filtered rather than shown blank: a support email row with nothing beside
     * it reads as a platform that has stopped answering.
     *
     * @return array<string, string>
     */
    private function contacts(): array
    {
        $settings = PlatformSetting::values();

        return array_filter([
            'email' => (string) ($settings['support_email'] ?? ''),
            'whatsapp' => (string) ($settings['support_whatsapp'] ?? ''),
        ], fn (string $value) => $value !== '');
    }

    /**
     * The global scope already limits a reseller to their own tickets, so a
     * mismatched id 404s before reaching here. This is the second lock: it
     * survives someone adding withoutTenantScope() to the lookup later.
     */
    private function authoriseTicket(Request $request, SupportTicket $ticket): void
    {
        if ((int) $ticket->tenant_id !== (int) $request->user()->id) {
            throw new AccessDeniedHttpException('That ticket belongs to another account.');
        }
    }
}
