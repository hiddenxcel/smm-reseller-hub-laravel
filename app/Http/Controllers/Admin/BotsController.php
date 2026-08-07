<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\BotOverview;
use App\Services\Admin\SystemTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the two bots are doing across every reseller, and the wording they all
 * start with.
 *
 * One controller for both bots because the screens are the same shape — the
 * support bot simply has a ticket queue the order bot does not.
 */
class BotsController extends AdminController
{
    private const BOTS = ['order', 'support'];

    public function show(Request $request, string $bot, string $tab = 'overview'): Response
    {
        $this->authorise('tenants.view');

        abort_unless(in_array($bot, self::BOTS, true), 404);

        $overview = BotOverview::for($bot);

        return Inertia::render('Admin/Bots/Index', [
            'bot' => $bot,
            'tab' => $tab,
            'kpis' => $overview->kpis(),
            'canManage' => $this->can('billing.manage'),

            ...match ($tab) {
                'templates' => [
                    'templates' => SystemTemplates::rows(
                        $bot,
                        $request->string('lang')->toString() ?: 'en',
                    ),
                    'languages' => SystemTemplates::languages(),
                    'lang' => $request->string('lang')->toString() ?: 'en',
                ],
                'defaults' => ['defaults' => $overview->defaults()],
                default => [
                    'trend' => Inertia::defer(fn () => $overview->trend()),
                    'silent' => Inertia::defer(fn () => $overview->silent()),
                    'tickets' => $bot === 'support'
                        ? Inertia::defer(fn () => $overview->ticketStats())
                        : null,
                ],
            },
        ]);
    }

    /**
     * Write or clear a platform-default template.
     *
     * Changing one of these changes what every reseller who has not overridden
     * that key sends to their customers, so it needs the same grade as touching
     * money rather than the read-only grade that can view this screen.
     */
    public function saveTemplate(Request $request): RedirectResponse
    {
        $this->authorise('billing.manage');

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:50'],
            'lang' => ['required', 'string', 'max:5'],
            // Nullable clears the platform default, letting the bot fall back
            // to its built-in translation.
            'content' => ['nullable', 'string', 'max:4000'],
            'bot' => ['required', Rule::in(self::BOTS)],
        ]);

        abort_unless(SystemTemplates::isKnownKey($validated['key']), 422);

        SystemTemplates::save(
            $validated['key'],
            $validated['lang'],
            $validated['content'] ?? null,
        );

        return back()->with(
            'success',
            $validated['content'] === null || trim((string) $validated['content']) === ''
                ? 'Reset to the built-in wording.'
                : 'Saved. Resellers who have not written their own version will use this.',
        );
    }
}
