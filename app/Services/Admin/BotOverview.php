<?php

namespace App\Services\Admin;

use App\Enums\ServiceKey;
use App\Models\Subscription;
use App\Models\TenantWhatsApp;
use App\Models\Ticket;
use App\Services\Bots\BotSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How one bot is doing across every reseller running it.
 *
 * The reseller-facing bot screens answer "is MY bot working". This answers "are
 * these bots working", which is a different question with a different failure
 * mode: one reseller's silent number is their problem, but forty silent numbers
 * in a week is ours.
 *
 * Settings are read but not written. The platform's defaults live in
 * BotSettings::DEFAULTS — a constant in code, deployed rather than edited — and
 * a screen that wrote per-reseller settings from here would be an admin
 * reaching into a reseller's configuration without impersonating, which the
 * console deliberately does not do.
 */
class BotOverview
{
    public function __construct(private string $bot) {}

    public static function for(string $bot): self
    {
        return new self($bot);
    }

    /** The service key this bot is sold as. */
    private function serviceKey(): ServiceKey
    {
        return $this->bot === 'support' ? ServiceKey::SupportBot : ServiceKey::OrderBot;
    }

    public function kpis(): array
    {
        $today = Carbon::today();

        return [
            'subscribed' => Subscription::withoutTenantScope()
                ->forService($this->serviceKey())
                ->active()
                ->count(),
            'sandbox' => Subscription::withoutTenantScope()
                ->forService($this->serviceKey())
                ->where('status', 'sandbox')
                ->count(),
            'numbersConnected' => TenantWhatsApp::withoutTenantScope()
                ->where('bot_type', $this->bot)
                ->count(),
            'messagesToday' => DB::table('bot_messages')
                ->where('bot_type', $this->bot)
                ->where('created_at', '>=', $today)
                ->count(),
            'messages7d' => DB::table('bot_messages')
                ->where('bot_type', $this->bot)
                ->where('created_at', '>=', $today->copy()->subDays(6))
                ->count(),
            // Bots that are set up but have not spoken in 48 hours. This is the
            // number worth acting on: it is stalled onboarding, not churn.
            'silent' => $this->silentCount(),
        ];
    }

    /**
     * Daily message volume, both directions, for the trend chart.
     *
     * @return array<int, array{date: string, messages: int}>
     */
    public function trend(int $days = 14): array
    {
        $start = Carbon::today()->subDays($days - 1);

        $byDay = DB::table('bot_messages')
            ->where('bot_type', $this->bot)
            ->where('created_at', '>=', $start)
            ->selectRaw($this->dateExpression().' as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];

        for ($day = 0; $day < $days; $day++) {
            $date = $start->copy()->addDays($day)->toDateString();

            $series[] = [
                'date' => $date,
                'messages' => (int) ($byDay[$date] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Resellers whose bot has a number but has said nothing recently.
     *
     * The list, not just the count: this is a support queue, and the useful
     * form of it is who to contact.
     */
    public function silent(int $limit = 20): array
    {
        return TenantWhatsApp::withoutTenantScope()
            ->where('bot_type', $this->bot)
            ->whereNotIn('tenant_id', $this->activeTenantIds())
            ->with('tenant')
            ->limit($limit)
            ->get()
            ->map(fn (TenantWhatsApp $number) => [
                'tenantId' => $number->tenant_id,
                'tenant' => $number->tenant?->business_name ?? 'Deleted reseller',
                'number' => $number->display_number ?? $number->phone_number_id,
                'status' => $number->status,
                'lastReplyAt' => $this->lastReplyFor($number->tenant_id)?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The settings every reseller inherits until they change something.
     *
     * Read-only on purpose — these come from BotSettings::DEFAULTS, which is
     * code. Shown so an admin can see what a new reseller starts with without
     * opening the source.
     */
    public function defaults(): array
    {
        $defaults = BotSettings::DEFAULTS;

        return [
            'commands' => $defaults['commands'],
            'spam' => $defaults['spam'],
            'response' => $defaults['response'],
            'shop' => [
                'currency' => $defaults['shop']['currency'],
                'lang' => $defaults['shop']['lang'],
                'min_topup' => $defaults['shop']['min_topup'],
                'referral_percent' => $defaults['shop']['referral_percent'],
                'support_mode' => $defaults['shop']['support_mode'],
            ],
        ];
    }

    /** Support-bot only: how the ticket queue is doing platform-wide. */
    public function ticketStats(): array
    {
        $counts = Ticket::withoutTenantScope()
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        return [
            'open' => (int) ($counts['open'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'resolved' => (int) ($counts['resolved'] ?? 0),
            'closed' => (int) ($counts['closed'] ?? 0),
            // Conversations a person has claimed and not handed back. A large
            // number here means staff are answering what the bot should.
            'handedOver' => Ticket::withoutTenantScope()
                ->whereNotNull('handed_over_at')
                ->openish()
                ->count(),
        ];
    }

    // ---- internals -------------------------------------------------------

    private function silentCount(): int
    {
        return TenantWhatsApp::withoutTenantScope()
            ->where('bot_type', $this->bot)
            ->whereNotIn('tenant_id', $this->activeTenantIds())
            ->count();
    }

    /** Tenants whose bot has sent something in the last two days. */
    private function activeTenantIds(): array
    {
        return DB::table('bot_messages')
            ->where('bot_type', $this->bot)
            ->where('direction', 'out')
            ->where('created_at', '>=', Carbon::now()->subDays(2))
            ->distinct()
            ->pluck('tenant_id')
            ->all();
    }

    private function lastReplyFor(int $tenantId): ?Carbon
    {
        $last = DB::table('bot_messages')
            ->where('tenant_id', $tenantId)
            ->where('bot_type', $this->bot)
            ->where('direction', 'out')
            ->max('created_at');

        return $last ? Carbon::parse($last) : null;
    }

    private function dateExpression(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(created_at, 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', created_at)";
    }
}
