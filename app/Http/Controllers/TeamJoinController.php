<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Services\Team\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where an invited person accepts, sets a password and is let in.
 *
 * The link is the only credential, so it is single-use and short-lived: a
 * successful accept deletes the token, and an expired or already-used link
 * shows the same plain page whichever it was — telling the two apart would only
 * help someone guessing tokens.
 */
class TeamJoinController extends Controller
{
    public function show(string $token): Response
    {
        $member = TeamMember::findByInvite($token);

        if ($member === null) {
            return Inertia::render('Team/Join', ['valid' => false]);
        }

        return Inertia::render('Team/Join', [
            'valid' => true,
            'email' => $member->email,
            'name' => $member->name,
            'business' => $member->tenant->business_name,
            'role' => TeamAccess::LABELS[$member->role] ?? $member->role,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $member = TeamMember::findByInvite($token);

        if ($member === null) {
            return redirect()->route('team.join', $token);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $member->forceFill([
            'name' => $data['name'],
            'password_hash' => Hash::make($data['password']),
            'accepted_at' => now(),
            'last_login_at' => now(),
            // Spent: the same link cannot be used to set the password again.
            'invite_token_hash' => null,
            'invite_expires_at' => null,
        ])->save();

        // Whoever was signed in here a moment ago is replaced, not joined.
        Auth::guard('tenant')->login($member->tenant);

        $request->session()->regenerate();
        $request->session()->put('team_member_id', $member->id);

        return redirect()->route('dashboard')->with('success', 'Welcome — you are in.');
    }
}
