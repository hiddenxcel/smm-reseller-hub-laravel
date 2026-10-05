<?php

namespace App\Http\Controllers;

use App\Enums\ServiceKey;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Subscription;
use App\Services\Billing\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the tenant's profile form.
     */
    public function edit(Request $request): Response
    {
        $tenant = $request->user();

        return Inertia::render('Profile/Edit', [
            'status' => session('status'),
            'account' => [
                'memberSince' => $tenant->created_at?->toIso8601String(),
                'credit' => (float) $tenant->referral_credit,
                'plan' => $this->plan((int) $tenant->id),
            ],
        ]);
    }

    /**
     * Update the tenant's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());
        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the tenant's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $tenant = $request->user();

        Auth::guard('tenant')->logout();

        $tenant->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * What the reseller holds, a line each — the same three states the
     * dashboard and billing use, so the profile never disagrees with them.
     *
     * @return array<int, array{name: string, state: string, daysLeft: int|null}>
     */
    private function plan(int $tenantId): array
    {
        $subscriptions = Subscription::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy(fn (Subscription $subscription) => $subscription->service_key instanceof ServiceKey
                ? $subscription->service_key->value
                : (string) $subscription->service_key);

        $states = Subscription::stateMap($tenantId);

        return collect(Pricing::sellableServices())
            ->map(function (array $service) use ($subscriptions, $states) {
                $endsAt = $subscriptions->get($service['key'])?->ends_at;

                return [
                    'name' => (string) $service['name'],
                    'state' => $states[$service['key']] ?? 'locked',
                    // Counted here, not in the browser: a wrong clock would
                    // otherwise tell a reseller their plan is fine.
                    'daysLeft' => $endsAt === null
                        ? null
                        : (int) now()->startOfDay()->diffInDays($endsAt->copy()->startOfDay(), false),
                ];
            })
            ->values()
            ->all();
    }
}
