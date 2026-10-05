<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Notifications\AssistantLeadReceived;
use App\Services\Assistant\AssistantReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * The website chat widget's two endpoints.
 *
 * JSON rather than Inertia, unlike everything else in this directory: the
 * widget floats above whatever page the visitor is reading, and answering a
 * question must not move them off it. That is also why the CTA in an answer is
 * a client-side visit — the chat survives the navigation.
 */
class AssistantController extends Controller
{
    /**
     * How many questions a conversation runs to before the offer of a human
     * is considered — and only considered; see shouldOfferAHuman().
     */
    private const ESCALATE_AFTER = 3;

    public function __construct(private AssistantReply $replies) {}

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Long enough for a real question with context, short enough that
            // nobody can paste a novel into a prompt we pay per token for.
            'message' => ['required', 'string', 'max:500'],
            'token' => ['nullable', 'uuid'],
            'page' => ['nullable', 'string', 'max:200'],
            // The language the visitor picked, if they did. Unknown codes are
            // ignored rather than rejected: a stale client must still work.
            'lang' => ['nullable', 'string', 'max:8'],
        ]);

        $page = $this->safePage($validated['page'] ?? null);

        $conversation = AssistantConversation::forToken(
            $validated['token'] ?? null,
            $request,
            $page,
        );

        $answer = $this->replies->answer(
            $conversation,
            trim($validated['message']),
            $page,
            $validated['lang'] ?? null,
        );

        return response()->json([
            ...$answer,
            // Returned every time, not only on the first turn: a visitor whose
            // token we did not recognise has silently been given a new
            // conversation, and their browser needs to know which one.
            // `locale` rides along in $answer: the widget's own furniture —
            // the composer, the escalation row, the form — speaks the same
            // language as the answers. A Kiswahili reply under an English
            // "Need a person?" is half a translation, which reads worse than
            // none.
            'token' => $conversation->session_token,
            'escalate' => $this->shouldOfferAHuman($conversation, $answer['answered_by']),
        ]);
    }

    /**
     * Whether to offer a person.
     *
     * The moment it admits it cannot help, obviously. Otherwise only when the
     * conversation has stopped going well: a long chat is not itself a problem
     * — somebody working through five questions and getting five answers is
     * the assistant doing its job, and interrupting that to suggest they talk
     * to a human instead reads as it giving up on them.
     *
     * What warrants the offer is a conversation the model is carrying alone.
     * Those are the questions nobody wrote an answer to, which is exactly
     * where its footing is least certain.
     */
    private function shouldOfferAHuman(AssistantConversation $conversation, string $answeredBy): bool
    {
        if ($answeredBy === AssistantMessage::FALLBACK || $conversation->hasEscalated()) {
            return true;
        }

        if ($conversation->messages_count < self::ESCALATE_AFTER) {
            return false;
        }

        return $conversation->messages()
            ->where('role', 'assistant')
            ->where('answered_by', AssistantMessage::KNOWLEDGE)
            ->doesntExist();
    }

    /**
     * "Have someone call me."
     *
     * The end of the funnel this whole widget exists to feed: a name, a number
     * and a question, from somebody who was reading the pricing page a minute
     * ago.
     */
    public function lead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $conversation = AssistantConversation::where('session_token', $validated['token'])->first();

        if ($conversation === null) {
            return response()->json(['message' => 'That chat has expired. Please reopen it and try again.'], 422);
        }

        $conversation->forceFill([
            'lead_name' => $validated['name'],
            'lead_phone' => $validated['phone'],
            'lead_message' => $validated['message'] ?? null,
            'escalated_at' => $conversation->escalated_at ?? now(),
        ])->save();

        $this->notify($conversation);

        return response()->json([
            'ok' => true,
            // So the widget can offer WhatsApp as well: some people would
            // rather not wait for a reply, and the row is already saved either
            // way.
            'whatsapp' => $this->whatsappLink(),
        ]);
    }

    /**
     * Tell the team, but never at the visitor's expense.
     *
     * The lead is already stored and visible in the console before this runs,
     * so a failure here loses a notification rather than the lead itself —
     * which is why it is logged and swallowed rather than surfaced as an error
     * to somebody who did nothing wrong.
     *
     * The notification is queued, so on a real queue this catches only the
     * dispatch and the send fails later in the worker (where Laravel logs and
     * retries it). It still matters on the sync driver, where the send happens
     * inline and an unreachable mail host would otherwise 500 a visitor whose
     * details we already have.
     */
    private function notify(AssistantConversation $conversation): void
    {
        $to = config('mail.contact_to');

        if (blank($to)) {
            Log::warning('Assistant lead captured with no contact address configured', [
                'conversation' => $conversation->id,
            ]);

            return;
        }

        try {
            Notification::route('mail', $to)->notify(new AssistantLeadReceived($conversation));
        } catch (Throwable $e) {
            Log::error('Assistant lead notification failed', [
                'conversation' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The page the visitor is on, reduced to a path we would recognise.
     *
     * It reaches the model as context, so it is treated as untrusted text: a
     * full URL from another host, or a query string carrying instructions, has
     * no business being read back into a prompt.
     */
    private function safePage(?string $page): ?string
    {
        if (blank($page)) {
            return null;
        }

        $path = parse_url($page, PHP_URL_PATH) ?: $page;
        $path = '/'.ltrim(Str::before($path, '?'), '/');

        return preg_match('#^/[\w\-/]*$#', $path) === 1
            ? Str::limit($path, 120, '')
            : null;
    }

    private function whatsappLink(): ?string
    {
        $number = preg_replace('/\D/', '', (string) config('services.demo_whatsapp_number'));

        return blank($number) ? null : "https://wa.me/{$number}";
    }
}
