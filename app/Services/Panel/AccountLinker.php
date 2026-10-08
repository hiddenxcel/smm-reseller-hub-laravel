<?php

namespace App\Services\Panel;

use App\Models\PanelAccountLink;
use App\Models\TenantPanel;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Proves that a WhatsApp number belongs to an account on the reseller's panel.
 *
 * The customer names an account; we put a six-digit code in that account's own
 * Tickets (only its owner can read them) and the customer types it back. Until
 * then nothing about the account is shown or acted on.
 *
 * Care taken here: the reply to "send me a code" is the same whether or not the
 * account exists, so this cannot be used to find out who has an account; a code
 * lives ten minutes, allows five tries, is stored only as a keyed hash, and a
 * number may ask for three codes an hour.
 */
class AccountLinker
{
    public const CODE_MINUTES = 10;

    public const MAX_TRIES = 5;

    public const MAX_CODES_PER_HOUR = 3;

    public const SENT = 'sent';

    public const THROTTLED = 'throttled';

    public const FAILED = 'failed';

    public const OK = 'ok';

    public const WRONG = 'wrong';

    public const EXPIRED = 'expired';

    public const NONE = 'none';

    public function linked(TenantPanel $panel, string $phone): ?PanelAccountLink
    {
        $link = $this->row($panel, $phone);

        return $link?->isVerified() ? $link : null;
    }

    /** Send a code to the account named by `$identifier` (username or email). */
    public function start(TenantPanel $panel, PanelAdminClient $client, string $phone, string $identifier): string
    {
        $key = "panel-link:{$panel->id}:{$phone}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_CODES_PER_HOUR)) {
            return self::THROTTLED;
        }

        RateLimiter::hit($key, 3600);

        $found = $client->findUser($identifier);

        if ($found->failed) {
            return self::FAILED;
        }

        $user = $found->get('user');

        // No such account, or more than one match: say "sent" all the same.
        if (! is_array($user)) {
            return self::SENT;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $ticket = $client->sendTicket(
            $user['id'],
            'WhatsApp verification code',
            "Your verification code is {$code}.\n\nSend it in the WhatsApp chat to link this account. It expires in ".self::CODE_MINUTES." minutes. If you did not ask for it, ignore this ticket.",
        );

        if ($ticket->failed) {
            return self::FAILED;
        }

        PanelAccountLink::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $panel->tenant_id, 'panel_id' => $panel->id, 'customer_phone' => $phone],
            [
                'pending_user_id' => $user['id'],
                'pending_username' => mb_substr($user['username'], 0, 100),
                'code_hash' => $this->hash($code),
                'code_expires_at' => now()->addMinutes(self::CODE_MINUTES),
                'attempts' => 0,
            ],
        );

        return self::SENT;
    }

    /** Check the code the customer typed; on success the number is linked. */
    public function verify(TenantPanel $panel, string $phone, string $code): string
    {
        $link = $this->row($panel, $phone);

        if ($link === null || $link->code_hash === null) {
            return self::NONE;
        }

        if ($link->code_expires_at === null || $link->code_expires_at->isPast() || $link->attempts >= self::MAX_TRIES) {
            $this->clearPending($link);

            return self::EXPIRED;
        }

        if (! hash_equals($link->code_hash, $this->hash($code))) {
            $link->increment('attempts');

            return $link->attempts >= self::MAX_TRIES ? $this->lock($link) : self::WRONG;
        }

        $link->forceFill([
            'panel_user_id' => $link->pending_user_id,
            'panel_username' => $link->pending_username,
            'verified_at' => now(),
            'pending_user_id' => null,
            'pending_username' => null,
            'code_hash' => null,
            'code_expires_at' => null,
            'attempts' => 0,
        ])->save();

        return self::OK;
    }

    public function triesLeft(TenantPanel $panel, string $phone): int
    {
        return max(0, self::MAX_TRIES - (int) $this->row($panel, $phone)?->attempts);
    }

    public function unlink(TenantPanel $panel, string $phone): void
    {
        PanelAccountLink::withoutTenantScope()
            ->where('tenant_id', $panel->tenant_id)
            ->where('panel_id', $panel->id)
            ->where('customer_phone', $phone)
            ->delete();
    }

    private function row(TenantPanel $panel, string $phone): ?PanelAccountLink
    {
        return PanelAccountLink::withoutTenantScope()
            ->where('tenant_id', $panel->tenant_id)
            ->where('panel_id', $panel->id)
            ->where('customer_phone', $phone)
            ->first();
    }

    private function lock(PanelAccountLink $link): string
    {
        $this->clearPending($link);

        return self::EXPIRED;
    }

    private function clearPending(PanelAccountLink $link): void
    {
        $link->forceFill([
            'pending_user_id' => null,
            'pending_username' => null,
            'code_hash' => null,
            'code_expires_at' => null,
            'attempts' => 0,
        ])->save();
    }

    /** Keyed, so a leaked table does not reveal a code that is still live. */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
