<?php

namespace App\Http\Middleware;

use App\Models\Announcement;
use App\Services\Admin\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                // The admin behind the screen, if there is one. Present on both
                // sides: the console reads it for the sidebar, and a tenant page
                // reads it to know it is being viewed rather than used.
                'admin' => fn () => $this->admin(),
            ],
            // Set only while an admin is inside a reseller's account, so the
            // banner is a single truthy check rather than a comparison of two
            // sessions on every page.
            'impersonation' => fn () => $this->impersonation($request),
            // Platform notices. A closure, so the query only runs on a response
            // that actually renders a reseller page.
            'announcements' => fn () => $this->announcements($request),
            // One-shot messages from the action just performed, shown as
            // toasts. Closures so the session is only read on a response that
            // actually carries one.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * The authenticated super-admin, reduced to what a screen needs.
     *
     * Never the model: it carries a password hash and a login IP, and this prop
     * is serialised into the page of whichever reseller is being viewed.
     *
     * @return array<string, mixed>|null
     */
    private function admin(): ?array
    {
        $admin = Auth::guard('superadmin')->user();

        return $admin === null ? null : [
            'id' => $admin->id,
            'username' => $admin->username,
            'name' => $admin->displayName(),
            'role' => $admin->role,
        ];
    }

    /**
     * Live platform notices, for the reseller dashboard's banner.
     *
     * Only for a signed-in reseller: the landing page and the admin console
     * have no use for them, and the query would run on every request otherwise.
     *
     * @return array<int, array<string, mixed>>
     */
    private function announcements(Request $request): array
    {
        if (Auth::guard('tenant')->guest()) {
            return [];
        }

        return Announcement::live()
            ->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'level' => $announcement->level,
                'dismissible' => (bool) $announcement->dismissible,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function impersonation(Request $request): ?array
    {
        if (! Impersonation::isActive($request)) {
            return null;
        }

        $tenant = $request->user();

        return [
            'tenant' => $tenant?->business_name,
            'readOnly' => true,
        ];
    }
}
