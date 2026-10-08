<?php

namespace App\Services\Bots;

use App\Models\BotMessage;
use App\Models\StaffAlert;
use App\Models\Tenant;
use App\Notifications\StaffAlertEmail;
use Illuminate\Support\Arr;

/**
 * Telling the reseller's team that something needs them, and knowing whether
 * it got through.
 *
 * WhatsApp only delivers a free-form message to someone who has written to the
 * number within the last 24 hours. A staff member who has not is unreachable,
 * and sending anyway would only fail while leaving a message in the history
 * that looks delivered. So reachability is worked out first, a person who
 * cannot be reached is recorded as such with the reason, and email — which has
 * no such rule — covers for them.
 */
class StaffAlerts
{
    public const WINDOW_HOURS = 24;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const NEVER = 'never';

    /** When to email: only if WhatsApp could not reach everyone, always, or never. */
    public const EMAIL_MODES = ['failed', 'all', 'off'];

    private const REASONS = [
        self::NEVER => 'has never messaged the bot',
        self::CLOSED => 'has not messaged the bot in the last 24 hours',
        'rejected' => 'WhatsApp refused the message',
    ];

    /** @return array<int, string> the team's numbers as digits, without repeats */
    public static function numbers(int $tenantId, string $bot): array
    {
        $numbers = Arr::get(BotSettings::for($tenantId, $bot), 'staff.numbers', []);

        return array_values(array_unique(array_filter(array_map(
            fn ($number) => preg_replace('/\D/', '', (string) $number) ?? '',
            $numbers,
        ))));
    }

    /** Can a free-form message reach this number right now? */
    public function reachability(int $tenantId, string $phone): string
    {
        $last = BotMessage::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $phone)
            ->where('direction', 'in')
            ->max('created_at');

        if ($last === null) {
            return self::NEVER;
        }

        return \Illuminate\Support\Carbon::parse($last)->gt(now()->subHours(self::WINDOW_HOURS))
            ? self::OPEN
            : self::CLOSED;
    }

    public static function reasonText(?string $reason): string
    {
        return self::REASONS[$reason ?? ''] ?? 'it did not arrive';
    }

    /** Tell everyone on the team, and email as the settings say. */
    public function notify(Tenant $tenant, string $bot, BotMessenger $messenger, string $message): void
    {
        $tenantId = (int) $tenant->id;
        $numbers = self::numbers($tenantId, $bot);
        $undelivered = [];

        foreach ($numbers as $phone) {
            $outcome = $this->deliver($tenantId, $bot, $messenger, $phone, $message, false);

            if ($outcome !== null) {
                $undelivered[$phone] = self::reasonText($outcome);
            }
        }

        // A rehearsal in the simulator must not email anybody.
        if (BotSimulation::active()) {
            return;
        }

        $mode = (string) Arr::get(BotSettings::for($tenantId, $bot), 'staff.email', 'failed');
        $mode = in_array($mode, self::EMAIL_MODES, true) ? $mode : 'failed';

        $send = $mode === 'all' || ($mode === 'failed' && ($numbers === [] || $undelivered !== []));

        if ($send && filled($tenant->email)) {
            $tenant->notify(new StaffAlertEmail(
                $message,
                $bot === 'support' ? 'Support Bot' : 'Order Bot',
                $undelivered,
                $numbers !== [],
            ));
        }
    }

    /**
     * A test, so a number can be checked before it is needed.
     *
     * @return array{status: string, reason: ?string}
     */
    public function test(Tenant $tenant, string $bot, BotMessenger $messenger, string $phone): array
    {
        $outcome = $this->deliver(
            (int) $tenant->id,
            $bot,
            $messenger,
            $phone,
            "✅ Test alert from {$tenant->business_name}. If you can read this, you will be told here when a customer needs you.",
            true,
        );

        return ['status' => $outcome === null ? 'sent' : 'failed', 'reason' => $outcome];
    }

    /**
     * What the settings screen shows: who can be reached now, and what was
     * recently sent and how it went.
     *
     * @return array<string, mixed>
     */
    public function overview(Tenant|int $tenant, string $bot): array
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;
        $email = $tenant instanceof Tenant ? $tenant->email : Tenant::query()->whereKey($tenantId)->value('email');
        $mode = (string) Arr::get(BotSettings::for($tenantId, $bot), 'staff.email', 'failed');

        return [
            'numbers' => array_map(
                fn (string $phone) => ['phone' => $phone, 'state' => $this->reachability($tenantId, $phone)],
                self::numbers($tenantId, $bot),
            ),
            'recent' => StaffAlert::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('bot_type', $bot)
                ->orderByDesc('id')
                ->limit(8)
                ->get()
                ->map(fn (StaffAlert $alert) => [
                    'id' => $alert->id,
                    'to' => $alert->to_phone,
                    'message' => $alert->message,
                    'status' => $alert->status,
                    'reason' => $alert->status === 'failed' ? self::reasonText($alert->reason) : null,
                    'isTest' => $alert->is_test,
                    'at' => $alert->created_at?->toIso8601String(),
                ])
                ->all(),
            'emailMode' => in_array($mode, self::EMAIL_MODES, true) ? $mode : 'failed',
            'email' => $email,
        ];
    }

    /** @return string|null null when delivered, otherwise the reason it was not */
    private function deliver(int $tenantId, string $bot, BotMessenger $messenger, string $phone, string $message, bool $isTest): ?string
    {
        $state = BotSimulation::active() ? self::OPEN : $this->reachability($tenantId, $phone);

        // Known not to be reachable: do not send, and do not leave a message in
        // the history that looks delivered.
        $reason = $state === self::OPEN
            ? ($messenger->sendText($phone, $message) ? null : 'rejected')
            : $state;

        if (! BotSimulation::active()) {
            StaffAlert::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'bot_type' => $bot,
                'to_phone' => $phone,
                'message' => mb_substr($message, 0, 500),
                'status' => $reason === null ? 'sent' : 'failed',
                'reason' => $reason,
                'is_test' => $isTest,
            ]);
        }

        return $reason;
    }
}
