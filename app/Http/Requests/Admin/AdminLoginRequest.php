<?php

namespace App\Http\Requests\Admin;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admins log in with a username, not an email — the super-admin table has no
 * email requirement and never had one.
 *
 * Throttling is tighter than the tenant login's five attempts. This form is the
 * front door to every reseller's data at once, and nobody legitimate is
 * guessing at it.
 */
class AdminLoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 3;

    private const DECAY_SECONDS = 300;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'username' => $this->string('username')->toString(),
            'password' => $this->string('password')->toString(),
            // A disabled admin fails here rather than after a successful
            // password check, so being taken out of service cannot be told
            // apart from a wrong password.
            'status' => 'active',
        ];

        // No "remember me": an admin session that survives a closed browser is
        // a stolen laptop away from the whole platform.
        if (! Auth::guard('superadmin')->attempt($credentials)) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'username' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return 'admin|'.Str::transliterate(Str::lower($this->string('username'))).'|'.$this->ip();
    }
}
