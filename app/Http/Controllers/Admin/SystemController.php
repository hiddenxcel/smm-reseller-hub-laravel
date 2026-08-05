<?php

namespace App\Http\Controllers\Admin;

use App\Models\ActivityLog;
use App\Models\BlockedIp;
use App\Models\PlatformSetting;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\AuditFilters;
use App\Services\Admin\AuditQuery;
use App\Services\Admin\Backups;
use App\Services\Admin\SecurityOverview;
use App\Services\Billing\PlatformGateways;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Settings, security, the audit trail and backups.
 *
 * One controller because the four screens share an audience — the owner — and
 * splitting them would mean four files that each hold one screen's worth of
 * validation.
 */
class SystemController extends AdminController
{
    // ---- settings --------------------------------------------------------

    public function settings(): Response
    {
        $this->authoriseOwner();

        return Inertia::render('Admin/System/Settings', [
            'settings' => PlatformSetting::values(),
            // Gateway credentials live in .env, not the database — see the
            // platform_settings migration. Reported as configured or not, never
            // shown and never editable from a browser session.
            'gateways' => collect(config('billing.gateways', []))
                ->map(fn (array $gateway, string $code) => [
                    'code' => $code,
                    'label' => $gateway['label'],
                    'type' => $gateway['type'],
                    'configured' => PlatformGateways::isConfigured($code),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->authoriseOwner();

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'support_email' => ['nullable', 'email', 'max:190'],
            'support_whatsapp' => ['nullable', 'string', 'max:30'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'terms_url' => ['nullable', 'url', 'max:255'],
            'privacy_url' => ['nullable', 'url', 'max:255'],
            'referral_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'registration_open' => ['required', 'boolean'],
        ]);

        $before = PlatformSetting::values();

        // Laravel's ConvertEmptyStringsToNull turns a blank field into null on
        // the way in, but a cleared setting is stored as '' — null there would
        // read as "never set" and fall back to the default, quietly restoring
        // whatever the admin just deleted. Normalising once, before both the
        // write and the comparison, keeps the two agreeing.
        $normalised = collect($validated)
            ->map(fn ($value) => $value ?? '')
            ->all();

        foreach ($normalised as $key => $value) {
            PlatformSetting::put($key, $value, $this->admin()->id);
        }

        AdminAudit::record('settings.update', [
            'changed' => collect($normalised)
                ->filter(fn ($value, $key) => ($before[$key] ?? null) !== $value)
                ->keys()
                ->all(),
        ]);

        return back()->with('success', 'Settings saved.');
    }

    // ---- security --------------------------------------------------------

    public function security(): Response
    {
        $this->authoriseOwner();

        $overview = SecurityOverview::make();

        return Inertia::render('Admin/System/Security', [
            'kpis' => $overview->kpis(),
            'blockedIps' => $overview->blockedIps(),
            'adminAccess' => Inertia::defer(fn () => $overview->adminAccess()),
            'adminLogins' => Inertia::defer(fn () => $overview->adminLogins()),
            'impersonations' => Inertia::defer(fn () => $overview->impersonations()),
        ]);
    }

    public function blockIp(Request $request): RedirectResponse
    {
        $this->authoriseOwner();

        $validated = $request->validate([
            'ip' => ['required', 'ip'],
            'reason' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $blocked = SecurityOverview::block(
            $validated['ip'],
            $validated['reason'] ?? null,
            isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
        );

        if (! $blocked) {
            // Blocking the address you are sitting behind loses you the console.
            return back()->with('error', 'That is your own address — blocking it would lock you out.');
        }

        return back()->with('success', "{$validated['ip']} is blocked.");
    }

    public function unblockIp(BlockedIp $blockedIp): RedirectResponse
    {
        $this->authoriseOwner();

        $ip = $blockedIp->ip;

        SecurityOverview::unblock($blockedIp);

        return back()->with('success', "{$ip} is no longer blocked.");
    }

    // ---- audit -----------------------------------------------------------

    public function audit(Request $request): Response
    {
        $this->authorise('audit.view');

        $filters = AuditFilters::fromRequest($request);
        $query = AuditQuery::make();

        $page = $query->paginate($filters);
        $names = $query->actorNames($page->items());

        return Inertia::render('Admin/System/Audit', [
            'entries' => [
                'data' => collect($page->items())
                    ->map(fn (ActivityLog $entry) => AuditQuery::toRow($entry, $names))
                    ->all(),
                'meta' => [
                    'currentPage' => $page->currentPage(),
                    'lastPage' => $page->lastPage(),
                    'perPage' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
            ],
            'filters' => $filters->toArray(),
            'isFiltered' => $filters->isFiltered(),
            'tabCounts' => Inertia::defer(fn () => $query->tabCounts($filters)),
            'actions' => Inertia::defer(fn () => $query->actions()),
        ]);
    }

    // ---- backups ---------------------------------------------------------

    public function backups(): Response
    {
        $this->authoriseOwner();

        $backups = Backups::make();

        return Inertia::render('Admin/System/Backups', [
            'backups' => $backups->list(),
            'databaseSize' => $backups->databaseSize(),
        ]);
    }

    public function createBackup(): RedirectResponse
    {
        $this->authoriseOwner();

        $result = Backups::make()->create();

        if (! $result['ok']) {
            return back()->with('error', $result['error'] ?? 'The backup failed.');
        }

        return back()->with('success', "Backup written: {$result['file']}");
    }

    /**
     * Serve a dump.
     *
     * Through a controller rather than a public URL: a backup is a complete
     * copy of every reseller's data, and a guessable link would be the worst
     * leak the platform could have.
     */
    public function downloadBackup(string $file): BinaryFileResponse
    {
        $this->authoriseOwner();

        $path = Backups::make()->pathFor($file);

        abort_if($path === null, 404);

        return response()->download($path);
    }

    public function deleteBackup(string $file): RedirectResponse
    {
        $this->authoriseOwner();

        if (! Backups::make()->delete($file)) {
            return back()->with('error', 'No such backup.');
        }

        return back()->with('success', 'Backup deleted.');
    }

    private function authoriseOwner(): void
    {
        if ($this->admin()->role !== 'owner') {
            abort(403, 'Only an owner can reach this.');
        }
    }
}
