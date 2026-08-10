<?php

namespace App\Http\Controllers\Admin;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledge;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\AssistantInsights;
use App\Services\Assistant\AssistantKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The website assistant, from the inside.
 *
 * Two things live here and they feed each other. One is the knowledge base —
 * what the assistant may say. The other is what visitors actually asked, and
 * in particular what it could not answer.
 *
 * That second list is the point of this screen. Every question that came back
 * as a fallback is a knowledge row nobody has written yet, phrased by the
 * person who wanted it. Answering one costs a paragraph and stops the same
 * visitor being turned away tomorrow.
 */
class AssistantAdminController extends AdminController
{
    public function index(Request $request): Response
    {
        $this->authorise('tenants.view');

        return Inertia::render('Admin/Assistant/Index', [
            'stats' => AssistantInsights::stats(),
            'topQuestions' => AssistantInsights::topQuestions(),
            'unanswered' => AssistantInsights::unanswered(),
            'leads' => AssistantInsights::leads(),
            'knowledge' => AssistantKnowledge::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (AssistantKnowledge $entry) => [
                    'id' => $entry->id,
                    'topic' => $entry->topic,
                    'topicLabel' => $entry->topicLabel(),
                    'question' => $entry->question,
                    'question_sw' => $entry->question_sw,
                    'answer' => $entry->answer,
                    'answer_sw' => $entry->answer_sw,
                    'cta_label' => $entry->cta_label,
                    'cta_url' => $entry->cta_url,
                    'keywords' => $entry->keywords,
                    'status' => $entry->status,
                    'sort_order' => $entry->sort_order,
                ])->all(),
            'topics' => AssistantKnowledge::TOPICS,
            'enabled' => AssistantKey::isReady(),
            // The key itself never leaves the server — only whether one is in
            // place, where it came from, and its last four characters, so an
            // owner can tell which key is loaded without it being recoverable
            // from a screenshot or a cached page.
            // Not named `key`: React reserves that prop, so a component
            // receiving it would silently get undefined.
            'apiKey' => [
                'source' => AssistantKey::source(),
                'hint' => AssistantKey::hint(),
                'stored' => AssistantKey::secret()->exists,
                'active' => (bool) AssistantKey::secret()->enabled,
            ],
            'canManage' => $this->can('tenants.edit'),
            'canManageKey' => $this->isOwner(),
        ]);
    }

    /**
     * Store the platform's DeepSeek key without a deploy.
     *
     * Owner-only, and the value is written but never read back — see
     * PlatformSecret. An .env key still wins, and the screen says so, because
     * an owner who pastes a key here and sees nothing change would otherwise
     * conclude the save failed.
     */
    public function saveKey(Request $request): RedirectResponse
    {
        $this->authoriseOwner('change the assistant key');

        $validated = $request->validate([
            // Blank is allowed on an update — it means "leave the stored key
            // alone", so switching the assistant off and on again does not
            // require pasting the key a second time.
            'key' => ['nullable', 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
        ]);

        $secret = AssistantKey::secret();

        if (filled($validated['key'])) {
            $secret->value_enc = trim($validated['key']);
        }

        $secret->enabled = $validated['enabled'];
        $secret->superadmin_id = $this->admin()->id;
        $secret->save();

        // The value itself is never audited: an audit log readable by support
        // staff would undo the point of encrypting it.
        AdminAudit::record('assistant.key.update', [
            'enabled' => $secret->enabled,
            'key_changed' => filled($validated['key']),
            'source' => AssistantKey::source(),
        ]);

        return back()->with('success', $this->keyMessage());
    }

    private function keyMessage(): string
    {
        return match (AssistantKey::source()) {
            'env' => 'Saved — but PLATFORM_DEEPSEEK_KEY in .env is still the one in use.',
            'database' => 'Saved. The assistant is live on the public pages.',
            'stored-disabled' => 'Key stored and switched off. The widget stays hidden until you turn it on.',
            default => 'Saved, but there is no key yet — the widget stays hidden.',
        };
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorise('tenants.edit');

        AssistantKnowledge::create($this->validated($request));

        return back()->with('success', 'The assistant can answer that now.');
    }

    public function update(Request $request, AssistantKnowledge $knowledge): RedirectResponse
    {
        $this->authorise('tenants.edit');

        $knowledge->update($this->validated($request));

        return back()->with('success', 'Answer updated.');
    }

    public function destroy(AssistantKnowledge $knowledge): RedirectResponse
    {
        $this->authorise('tenants.edit');

        $knowledge->delete();

        return back()->with('success', 'Answer removed.');
    }

    /** The whole conversation behind one row in the lists above. */
    public function show(AssistantConversation $conversation): Response
    {
        $this->authorise('tenants.view');

        return Inertia::render('Admin/Assistant/Conversation', [
            'conversation' => AssistantInsights::thread($conversation),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'topic' => ['required', Rule::in(array_keys(AssistantKnowledge::TOPICS))],
            'question' => ['required', 'string', 'max:255'],
            'question_sw' => ['nullable', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:2000'],
            'answer_sw' => ['nullable', 'string', 'max:2000'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            // Relative paths only. An answer is a place we send people, and an
            // absolute URL here is a way to send them off the site — or, if it
            // is ever fed from anywhere but this form, somewhere worse.
            'cta_url' => ['nullable', 'string', 'max:120', 'regex:/^\/[\w\-\/#]*$/'],
            'keywords' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(AssistantKnowledge::STATUSES)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [
            'cta_url.regex' => 'The link must be a path on this site, like /pricing.',
        ]);
    }
}
