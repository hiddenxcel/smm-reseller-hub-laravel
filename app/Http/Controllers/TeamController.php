<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TeamMember;
use App\Services\Team\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's view of who else can get into the account.
 *
 * Only the owner reaches this — a member is refused at the door by
 * RestrictTeamMember — and every query names the tenant, so one reseller can
 * never see, change or remove another's team by guessing an id.
 *
 * Invites are links, not emails: the plain link exists once, in the redirect
 * that creates it, and only its hash is stored. Losing it means issuing a new
 * one, which also kills the old.
 */
class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user();

        return Inertia::render('Team/Index', [
            'members' => TeamMember::where('tenant_id', $tenant->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (TeamMember $member) => $this->present($member))
                ->all(),
            'roles' => collect(TeamAccess::LABELS)
                ->map(fn (string $label, string $role) => [
                    'value' => $role,
                    'label' => $label,
                    'description' => TeamAccess::DESCRIPTIONS[$role],
                ])
                ->values()
                ->all(),
            'limit' => TeamMember::MAX_PER_TENANT,
            'inviteDays' => TeamMember::INVITE_DAYS,
            // Present only on the one response after an invite is created, then
            // gone: the plain link is not stored anywhere to show again.
            'invite' => session('invite'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->user();

        $data = $request->validate([
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:190',
                // One address, one person: it has to be free among the owners
                // too, or the login form could not tell whose it was.
                Rule::unique(Tenant::class, 'email'),
                Rule::unique(TeamMember::class, 'email'),
            ],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(TeamMember::ROLES)],
        ]);

        if (TeamMember::where('tenant_id', $tenant->id)->count() >= TeamMember::MAX_PER_TENANT) {
            return back()->with('error', 'Your team is full — remove someone before inviting another.');
        }

        $member = TeamMember::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'] ?? null,
            'email' => $data['email'],
            'role' => $data['role'],
        ]);

        return $this->withLink($member, 'Invite ready — send them the link.');
    }

    public function update(Request $request, int $member): RedirectResponse
    {
        $row = $this->owned($request, $member);

        $data = $request->validate([
            'role' => ['required', Rule::in(TeamMember::ROLES)],
        ]);

        $row->update(['role' => $data['role']]);

        return back()->with('success', 'Role updated.');
    }

    /** A new link, which also ends the old one — for a lost link or a forgotten password. */
    public function link(Request $request, int $member): RedirectResponse
    {
        return $this->withLink($this->owned($request, $member), 'New link ready — the old one no longer works.');
    }

    public function destroy(Request $request, int $member): RedirectResponse
    {
        $this->owned($request, $member)->delete();

        // Their next request finds no member and ends their session; nothing
        // else has to be done for the access to be gone.
        return back()->with('success', 'Removed. Their access ends on their next click.');
    }

    private function owned(Request $request, int $id): TeamMember
    {
        return TeamMember::where('tenant_id', $request->user()->id)->findOrFail($id);
    }

    private function withLink(TeamMember $member, string $message): RedirectResponse
    {
        $token = $member->issueInvite();

        return redirect()->route('team')->with([
            'success' => $message,
            'invite' => [
                'email' => $member->email,
                'url' => route('team.join', $token),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(TeamMember $member): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $member->role,
            'status' => $member->isActive()
                ? 'active'
                : ($member->isPending() ? 'pending' : 'expired'),
            'lastLoginAt' => $member->last_login_at?->toIso8601String(),
            'invitedAt' => $member->created_at?->toIso8601String(),
        ];
    }
}
