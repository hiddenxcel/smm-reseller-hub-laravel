<?php

namespace App\Http\Requests\Auth;

use App\Models\TeamMember;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            // The owner signing in. A marker left by an earlier team-member
            // sign-in in this same browser must not follow them and quietly
            // narrow their own account to someone else's role.
            $this->session()->forget('team_member_id');

            RateLimiter::clear($this->throttleKey());

            return;
        }

        if ($this->attemptAsTeamMember()) {
            RateLimiter::clear($this->throttleKey());

            return;
        }

        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.failed'),
        ]);
    }

    /**
     * Sign in as a team member: the reseller's account, narrowed to a role.
     *
     * Tried only after the owner's own login fails, through the same throttle,
     * so there is one form and one set of limits. The password is checked even
     * when no such member exists, against a throwaway hash, so how long the
     * answer takes does not reveal whether an address belongs to a team.
     */
    private function attemptAsTeamMember(): bool
    {
        $member = TeamMember::where('email', Str::lower($this->string('email')->toString()))->first();

        $hash = $member?->password_hash ?: '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

        $passwordMatches = Hash::check($this->string('password')->toString(), $hash);

        if ($member === null || ! $member->isActive() || ! $passwordMatches) {
            return false;
        }

        Auth::guard('tenant')->login($member->tenant, $this->boolean('remember'));

        $this->session()->put('team_member_id', $member->id);

        $member->forceFill(['last_login_at' => now()])->save();

        return true;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
