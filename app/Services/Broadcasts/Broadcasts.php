<?php

namespace App\Services\Broadcasts;

use App\Enums\ServiceKey;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Starting, stopping and resuming a reseller's broadcasts.
 *
 * Queuing writes one recipient row per customer straight away, with the ones
 * who cannot be reached right now already marked skipped and why. The sender
 * then only ever works through `pending` rows.
 */
class Broadcasts
{
    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * Why a new broadcast cannot start, or null when it can.
     */
    public function blocker(): ?string
    {
        if (! Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot)) {
            return 'Your Order Bot subscription is not active.';
        }

        $audience = Audience::for($this->tenant->id);

        if (! $audience->hasWhatsApp() && ! $audience->hasTelegram()) {
            return 'Connect a WhatsApp number or a Telegram bot first.';
        }

        $open = Broadcast::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', [Broadcast::SENDING, Broadcast::PAUSED])
            ->exists();

        if ($open) {
            return 'Wait for the current broadcast to finish, or cancel it.';
        }

        $today = Broadcast::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($today >= (int) config('broadcasts.daily_limit')) {
            return 'You have reached the daily limit of broadcasts. Try again tomorrow.';
        }

        return null;
    }

    /** Queue a broadcast. Null when nobody can be reached by it. */
    public function queue(string $message, string $segment): ?Broadcast
    {
        $audience = Audience::for($this->tenant->id);
        $whatsapp = $audience->hasWhatsApp();
        $telegram = $audience->hasTelegram();
        $window = $audience->insideWindow();

        $rows = [];

        foreach ($audience->phones($segment) as $phone) {
            $channel = Audience::channelOf($phone);
            $reason = null;

            if ($channel === Audience::TELEGRAM) {
                $reason = $telegram ? null : 'no_channel';
            } elseif (! $whatsapp) {
                $reason = 'no_channel';
            } elseif (! isset($window[$phone])) {
                $reason = 'needs_template';
            }

            $rows[] = [
                'tenant_id' => $this->tenant->id,
                'customer_phone' => $phone,
                'channel' => $channel,
                'status' => $reason === null ? BroadcastRecipient::PENDING : BroadcastRecipient::SKIPPED,
                'reason' => $reason,
            ];
        }

        $reachable = count(array_filter($rows, fn ($row) => $row['status'] === BroadcastRecipient::PENDING));

        if ($reachable === 0) {
            return null;
        }

        return DB::transaction(function () use ($message, $segment, $rows, $reachable) {
            $broadcast = Broadcast::withoutTenantScope()->create([
                'tenant_id' => $this->tenant->id,
                'message' => $message,
                'audience' => $segment,
                'status' => Broadcast::SENDING,
                'total' => count($rows),
                'skipped' => count($rows) - $reachable,
            ]);

            foreach (array_chunk($rows, 500) as $chunk) {
                BroadcastRecipient::withoutTenantScope()->insert(
                    array_map(fn ($row) => $row + ['broadcast_id' => $broadcast->id], $chunk),
                );
            }

            return $broadcast;
        });
    }

    /** Stop sending. Whoever has not been sent to yet is marked cancelled. */
    public function cancel(Broadcast $broadcast): void
    {
        if (! in_array($broadcast->status, [Broadcast::SENDING, Broadcast::PAUSED], true)) {
            return;
        }

        BroadcastRecipient::withoutTenantScope()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', BroadcastRecipient::PENDING)
            ->update(['status' => BroadcastRecipient::SKIPPED, 'reason' => 'cancelled']);

        $broadcast->forceFill(['status' => Broadcast::CANCELLED, 'finished_at' => now()])->save();
        BroadcastSender::recount($broadcast);
    }

    public function resume(Broadcast $broadcast): void
    {
        if ($broadcast->status === Broadcast::PAUSED) {
            $broadcast->forceFill(['status' => Broadcast::SENDING, 'note' => null])->save();
        }
    }
}
