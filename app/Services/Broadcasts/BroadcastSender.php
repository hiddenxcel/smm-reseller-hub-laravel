<?php

namespace App\Services\Broadcasts;

use App\Enums\ServiceKey;
use App\Models\BotCustomer;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantTelegramBot;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotMessenger;
use App\Services\Bots\BotMessengerFactory;

/**
 * Works through a broadcast a little at a time.
 *
 * Each run sends a small batch and stops, so a broadcast to hundreds of people
 * goes out over several minutes. Everything is re-checked at send time: a
 * customer who replied STOP or was blocked since the broadcast was queued is
 * skipped, and a WhatsApp customer whose 24 hours have run out is not sent a
 * message WhatsApp would refuse.
 */
class BroadcastSender
{
    public function __construct(private BotMessengerFactory $messengers) {}

    /** @return int how many messages went out in this run */
    public function run(Broadcast $broadcast): int
    {
        if ($broadcast->status !== Broadcast::SENDING) {
            return 0;
        }

        $tenant = Tenant::query()->find($broadcast->tenant_id);

        if ($tenant === null || $tenant->status !== 'active') {
            return 0;
        }

        if (! Subscription::isServiceActive($tenant->id, ServiceKey::OrderBot)) {
            $this->pause($broadcast, 'Your Order Bot subscription is not active.');

            return 0;
        }

        $audience = Audience::for($tenant->id);
        $sent = 0;

        foreach ([Audience::TELEGRAM, Audience::WHATSAPP] as $channel) {
            $messenger = $this->messengerFor($tenant, $channel);
            $limit = (int) config($channel === Audience::TELEGRAM ? 'broadcasts.telegram_per_run' : 'broadcasts.whatsapp_per_run');

            $batch = BroadcastRecipient::withoutTenantScope()
                ->where('broadcast_id', $broadcast->id)
                ->where('channel', $channel)
                ->where('status', BroadcastRecipient::PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->get();

            $failuresInARow = 0;

            foreach ($batch as $recipient) {
                if ($messenger === null) {
                    $this->mark($recipient, BroadcastRecipient::SKIPPED, 'no_channel');

                    continue;
                }

                $customer = BotCustomer::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->where('phone', $recipient->customer_phone)
                    ->first();

                if ($customer === null || $customer->blocked_at !== null) {
                    $this->mark($recipient, BroadcastRecipient::SKIPPED, 'blocked');

                    continue;
                }

                if ($customer->broadcast_opt_out_at !== null) {
                    $this->mark($recipient, BroadcastRecipient::SKIPPED, 'opted_out');

                    continue;
                }

                if ($channel === Audience::WHATSAPP && ! $audience->isInsideWindow($recipient->customer_phone)) {
                    $this->mark($recipient, BroadcastRecipient::SKIPPED, 'needs_template');

                    continue;
                }

                $ok = $messenger->sendText(
                    $recipient->customer_phone,
                    trim($broadcast->message),
                );

                if ($ok) {
                    $this->mark($recipient, BroadcastRecipient::SENT);
                    $sent++;
                    $failuresInARow = 0;
                } else {
                    $this->mark($recipient, BroadcastRecipient::FAILED, 'send_failed');
                    $failuresInARow++;

                    if ($failuresInARow >= (int) config('broadcasts.stop_after_failures')) {
                        $this->pause($broadcast, 'The channel refused several messages in a row, so it was paused. Check it, then resume.');
                        self::recount($broadcast);

                        return $sent;
                    }
                }

                if ($channel === Audience::WHATSAPP) {
                    usleep((int) config('broadcasts.whatsapp_pause_ms') * 1000);
                }
            }
        }

        self::recount($broadcast);

        $left = BroadcastRecipient::withoutTenantScope()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', BroadcastRecipient::PENDING)
            ->exists();

        if (! $left) {
            $broadcast->forceFill(['status' => Broadcast::DONE, 'finished_at' => now()])->save();
        }

        return $sent;
    }

    /** Refresh the counts on the broadcast from its recipients. */
    public static function recount(Broadcast $broadcast): void
    {
        $counts = BroadcastRecipient::withoutTenantScope()
            ->where('broadcast_id', $broadcast->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $broadcast->forceFill([
            'sent' => (int) ($counts[BroadcastRecipient::SENT] ?? 0),
            'failed' => (int) ($counts[BroadcastRecipient::FAILED] ?? 0),
            'skipped' => (int) ($counts[BroadcastRecipient::SKIPPED] ?? 0),
        ])->save();
    }

    private function messengerFor(Tenant $tenant, string $channel): ?BotMessenger
    {
        if ($channel === Audience::TELEGRAM) {
            $bot = TenantTelegramBot::withoutTenantScope()
                ->where('tenant_id', $tenant->id)->where('bot_type', 'order')->where('status', 'active')->first();

            return $bot === null ? null : $this->messengers->forTelegram($bot, $tenant);
        }

        $number = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $tenant->id)->where('bot_type', 'order')->where('status', 'active')->first();

        return $number === null ? null : $this->messengers->forWhatsApp($number, $tenant);
    }

    private function mark(BroadcastRecipient $recipient, string $status, ?string $reason = null): void
    {
        $recipient->forceFill([
            'status' => $status,
            'reason' => $reason,
            'sent_at' => $status === BroadcastRecipient::SENT ? now() : null,
        ])->save();
    }

    private function pause(Broadcast $broadcast, string $note): void
    {
        $broadcast->forceFill(['status' => Broadcast::PAUSED, 'note' => $note])->save();
    }
}
