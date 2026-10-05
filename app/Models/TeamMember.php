<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Someone a reseller has given access to their account.
 *
 * Deliberately not a login user of its own: a member signs in and is then
 * treated as the reseller's account, narrowed to a role — see
 * RestrictTeamMember. Nothing here is tenant-scoped automatically, so every
 * query names its tenant_id; a team list that forgot to would show one
 * reseller's staff to another.
 */
class TeamMember extends Model
{
    public const ROLES = ['admin', 'support', 'viewer'];

    /** How long an invite link works. */
    public const INVITE_DAYS = 7;

    /** A cap, not a plan limit: enough for any shop, small enough to audit. */
    public const MAX_PER_TENANT = 10;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'role',
        'password_hash',
        'invite_token_hash',
        'invite_expires_at',
        'accepted_at',
        'last_login_at',
    ];

    protected $hidden = ['password_hash', 'invite_token_hash'];

    protected function casts(): array
    {
        return [
            'invite_expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Has accepted and set a password, so can sign in. */
    public function isActive(): bool
    {
        return $this->accepted_at !== null && filled($this->password_hash);
    }

    /** Invited, link still good, not yet accepted. */
    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->invite_token_hash !== null
            && $this->invite_expires_at?->isFuture() === true;
    }

    /**
     * Issue a fresh invite link, replacing any earlier one.
     *
     * The plain token is returned once and never stored — only its hash is —
     * so it can be shown to the owner now and nowhere afterwards.
     */
    public function issueInvite(): string
    {
        $token = Str::random(40);

        $this->forceFill([
            'invite_token_hash' => self::hashToken($token),
            'invite_expires_at' => now()->addDays(self::INVITE_DAYS),
        ])->save();

        return $token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The member an invite token belongs to, if the link is still good. */
    public static function findByInvite(string $token): ?self
    {
        $member = self::where('invite_token_hash', self::hashToken($token))->first();

        if ($member === null || $member->invite_expires_at === null || $member->invite_expires_at->isPast()) {
            return null;
        }

        return $member;
    }
}
