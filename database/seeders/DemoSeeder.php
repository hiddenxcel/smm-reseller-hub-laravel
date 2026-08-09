<?php

namespace Database\Seeders;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Models\BotService;
use App\Models\GuaranteeRule;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantPaymentGateway;
use App\Models\TenantWhatsApp;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A reseller account with enough history to look at the screens.
 *
 * This existed only as hand-typed rows in one database until now, which meant
 * it did not survive a migrate:fresh and did not follow the project when the
 * working copy moved. Written down, `db:seed --class=DemoSeeder` rebuilds it
 * anywhere.
 *
 * Deliberately not part of DatabaseSeeder: a production deploy runs that, and
 * a demo login with a known password is the last thing it should create.
 *
 * The state is chosen to make the screens say something. A subscription four
 * days from expiry, a panel below its own low-balance threshold, a service
 * sold under cost, and a customer mid-handoff are each the case the UI was
 * built to handle — an account with none of them shows empty panels and
 * proves nothing.
 *
 * Everything else is paid up for a year, so the account reads as a working
 * shop rather than one falling apart in every direction at once.
 *
 * The trading history is generated from a fixed sequence rather than random
 * values: a demo that reshuffles on every rebuild cannot be talked through,
 * and a screenshot taken today stops matching the screen tomorrow.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const EMAIL = 'demo@wizard.test';

    private const PASSWORD = '12345678';

    /** The customer who asked for a human and is waiting on a reply. */
    private const WAITING = '255700111222';

    private const SETTLED = '255700333444';

    /** The customer who bought something on the order bot. */
    private const ORDERING = '255700555666';

    /** How far back the order history runs. */
    private const HISTORY_DAYS = 60;

    public function run(): void
    {
        $tenant = $this->tenant();

        $this->conversation($tenant);
        $this->guaranteeRules($tenant);
        $this->subscriptions($tenant);
        $this->invoices($tenant);

        // The shop itself. Ordered: panels hold services, services carry the
        // costs orders are priced against, and orders are what the customers
        // and payments hang off.
        $panels = $this->panels($tenant);
        $services = $this->services($tenant, $panels);
        $this->connections($tenant);
        $this->trading($tenant, $services);

        $this->command?->info('Demo reseller ready: '.self::EMAIL.' / '.self::PASSWORD);
    }

    /**
     * The password column is `password_hash`, not `password` — setting the
     * wrong name fails silently and leaves an account nobody can log into.
     */
    private function tenant(): Tenant
    {
        $tenant = Tenant::firstOrNew(['email' => self::EMAIL]);

        $tenant->forceFill([
            'business_name' => 'Demo Reseller',
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => 'active',
            'referral_credit' => '12.50',
        ])->save();

        return $tenant;
    }

    /**
     * Two conversations: one handed to a person and waiting, one the bot
     * finished by itself. The contrast is the point — the inbox flags the
     * first and leaves the second alone.
     */
    private function conversation(Tenant $tenant): void
    {
        $lines = [
            ['in', 'Habari, nimeagiza followers jana lakini hazijafika'],
            ['out', "📋 *Quick Menu (AI)*\n\nWelcome to Demo Reseller — AI & Human Support\n\n👇 Please choose an option:\n\n1️⃣ Refill\n2️⃣ Speed Up\n3️⃣ Cancel\n4️⃣ Partial / Fake Comp\n5️⃣ 👤 Talk to a Human Agent\n6️⃣ 📦 Order Status\n7️⃣ 💸 Balance Top-Up Issue\n8️⃣ ❓ AI FAQ"],
            ['in', '5'],
            ['out', "👤 Connecting you to our team — someone will reply here shortly.\nYou can keep typing; your messages reach them directly."],
            ['in', 'Order ID ni 4471, nilizilipia jana saa nne asubuhi'],
        ];

        foreach ($lines as [$direction, $text]) {
            BotMessage::withoutTenantScope()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'customer_phone' => self::WAITING,
                'message' => $text,
            ], ['direction' => $direction, 'bot_type' => 'support']);
        }

        BotCustomer::withoutTenantScope()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'phone' => self::WAITING,
        ], ['name' => 'Amina J.', 'balance' => '3.20', 'last_seen_at' => now()]);

        $ticket = Ticket::handoffFor($tenant->id, self::WAITING)
            ?? Ticket::openHandoff($tenant->id, self::WAITING, 'Followers not delivered — order 4471');

        TicketMessage::firstOrCreate([
            'ticket_id' => $ticket->id,
            'sender' => 'customer',
            'message' => 'Order ID ni 4471, nilizilipia jana saa nne asubuhi',
        ]);

        BotMessage::withoutTenantScope()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'customer_phone' => self::SETTLED,
            'message' => 'Asante, imefika sasa 👍',
        ], ['direction' => 'in', 'bot_type' => 'support']);

        $this->orderConversation($tenant);
    }

    /**
     * A sale, start to finish, on the order bot.
     *
     * Without this the order bot has no outbound message and the dashboard
     * reads it as "never replied" — on the very product the demo exists to
     * sell. The support bot having traffic while the order bot appears dead is
     * the wrong first impression.
     *
     * Timestamps are set explicitly: the health pill compares the last reply
     * against the last 24 hours, and rows created at seed time would age out
     * of "online" the day after a demo database was built.
     */
    private function orderConversation(Tenant $tenant): void
    {
        $lines = [
            ['in', 'Mambo'],
            ['out', "👋 Karibu Demo Reseller!\n\n1️⃣ Order mpya\n2️⃣ Angalia order\n3️⃣ Salio langu\n4️⃣ Ongeza salio"],
            ['in', '1'],
            ['out', "📱 Chagua platform:\n\n1️⃣ Instagram\n2️⃣ TikTok\n3️⃣ YouTube"],
            ['in', '1'],
            ['out', "✨ Instagram Followers — Real\n💰 3.50 / 1000\n\nAndika idadi unayotaka (100 – 50000):"],
            ['in', '1000'],
            ['out', '🔗 Tuma link ya profile yako:'],
            ['in', 'https://instagram.com/demo_shop'],
            ['out', "✅ *Order imepokelewa!*\n\nService: Instagram Followers — Real\nIdadi: 1,000\nGharama: 3.50\n\nOrder ID: *#DEMO-42*\nItaanza ndani ya dakika chache."],
        ];

        BotCustomer::withoutTenantScope()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'phone' => self::ORDERING,
        ], [
            'name' => 'Salehe M.',
            'lang' => 'sw',
            'country' => 'TZ',
            'balance' => '6.50',
            'total_spent' => '48.00',
            'first_deposit_done' => true,
            'last_seen_at' => now()->subMinutes(13),
        ]);

        foreach ($lines as $index => [$direction, $text]) {
            $message = BotMessage::withoutTenantScope()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'customer_phone' => self::ORDERING,
                'message' => $text,
            ], ['direction' => $direction, 'bot_type' => 'order']);

            // created_at only: bot_messages is a log and has no updated_at.
            $message->forceFill([
                'created_at' => now()->subMinutes(40 - ($index * 3)),
            ])->save();
        }
    }

    /** One exclusion and two grants, so precedence is visible on the screen. */
    private function guaranteeRules(Tenant $tenant): void
    {
        foreach ([
            ['guarantee', 'instagram followers', 30],
            ['guarantee', 'premium', 0],
            ['no_guarantee', 'cheap', null],
        ] as [$type, $keyword, $days]) {
            GuaranteeRule::withoutTenantScope()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'keyword' => $keyword,
            ], ['rule_type' => $type, 'refill_days' => $days, 'status' => 'active']);
        }
    }

    /**
     * A year on the services the demo is meant to show off, and one expiring
     * in four days.
     *
     * The near-expiry row is deliberate and worth keeping: the renewal warning
     * is a screen that only exists for this case, and an account where every
     * subscription is comfortably paid never renders it. Support Bot carries
     * it because the order bot is what a demo walks through first.
     *
     * AI Chat is included and active because AiAnswers gates on it — without a
     * live subscription the bot's AI replies are simply absent, which looks
     * like a broken feature rather than an unbought one.
     */
    private function subscriptions(Tenant $tenant): void
    {
        $yearly = [ServiceKey::OrderBot, ServiceKey::AiChat, ServiceKey::AiTickets];

        foreach ($yearly as $service) {
            Subscription::withoutTenantScope()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'service_key' => $service->value],
                [
                    'status' => SubscriptionStatus::Active,
                    'starts_at' => now()->subMonths(2),
                    'ends_at' => now()->addYear(),
                    'auto_renew' => true,
                ],
            );
        }

        // Paid and live, but nearly out — this is the one the dashboard warns
        // about.
        Subscription::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'service_key' => ServiceKey::SupportBot->value],
            [
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subMonths(2),
                'ends_at' => now()->addDays(4),
                'auto_renew' => false,
            ],
        );
    }

    /**
     * Two panels, in the two states the dashboard distinguishes.
     *
     * The second is deliberately below its own threshold: the low-balance
     * badge and the warning email are a pair of features that an account with
     * one healthy panel never exercises. Its threshold is set high on purpose,
     * to show that the figure is per panel rather than one number for the
     * account.
     *
     * @return array{main: TenantPanel, backup: TenantPanel}
     */
    private function panels(Tenant $tenant): array
    {
        $main = TenantPanel::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'api_url' => 'https://demo-panel.example.com/api/v2'],
            [
                'name' => 'Main Panel',
                'panel_type' => 'perfectpanel',
                'api_key_enc' => 'demo-key-not-a-real-credential',
                'api_version' => 'v2',
                'auth_method' => 'param',
                'last_checked_at' => now()->subMinutes(3),
                'last_balance' => '842.60',
                'balance_currency' => 'USD',
                'services_count' => 1840,
                'status' => 'active',
            ],
        );

        $backup = TenantPanel::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'api_url' => 'https://backup-panel.example.com/api/v2'],
            [
                'name' => 'Backup Panel',
                'panel_type' => 'custom',
                'api_key_enc' => 'demo-key-not-a-real-credential',
                'api_version' => 'v2',
                'auth_method' => 'header',
                'last_checked_at' => now()->subMinutes(3),
                'last_balance' => '18.40',
                'balance_currency' => 'USD',
                // Below the 50.00 this reseller chose for it, though well above
                // the platform default — the point of the setting.
                'low_balance_threshold' => '50.00',
                'services_count' => 320,
                'status' => 'active',
            ],
        );

        return ['main' => $main, 'backup' => $backup];
    }

    /**
     * A catalogue priced the way a real one is: mostly healthy margins, one
     * thin, and one sold below cost.
     *
     * The underwater row is the whole reason the dashboard flags them. It is a
     * mistake every reseller makes at least once — a panel raises its price
     * and the sell price stays where it was — and an account without one never
     * shows the warning that catches it.
     *
     * @param  array{main: TenantPanel, backup: TenantPanel}  $panels
     * @return array<int, BotService>
     */
    private function services(Tenant $tenant, array $panels): array
    {
        $rows = [
            // platform, category, name, cost, price, min, max, panel
            ['Instagram', 'Followers', 'Instagram Followers — Real', '1.4000', '3.5000', 100, 50000, 'main'],
            ['Instagram', 'Followers', 'Instagram Followers — Cheap', '0.6000', '1.8000', 100, 100000, 'main'],
            ['Instagram', 'Likes', 'Instagram Likes — Instant', '0.3000', '1.1000', 50, 20000, 'main'],
            ['Instagram', 'Views', 'Instagram Reel Views', '0.0800', '0.4000', 500, 500000, 'main'],
            ['TikTok', 'Followers', 'TikTok Followers', '2.1000', '4.8000', 100, 30000, 'main'],
            ['TikTok', 'Views', 'TikTok Views', '0.0500', '0.2500', 1000, 1000000, 'main'],
            ['TikTok', 'Likes', 'TikTok Likes', '0.4000', '1.3000', 50, 50000, 'backup'],
            ['YouTube', 'Views', 'YouTube Views — Slow', '1.9000', '4.2000', 500, 100000, 'main'],
            ['YouTube', 'Subscribers', 'YouTube Subscribers', '9.5000', '18.0000', 50, 5000, 'main'],
            // Thin but still positive — the one the margin list should surface
            // first without it being an outright loss.
            ['WhatsApp', 'Channel', 'WhatsApp Channel Members', '2.8000', '3.0000', 100, 20000, 'backup'],
            // Sold below cost: the panel raised its price and nobody noticed.
            ['Telegram', 'Members', 'Telegram Members — Premium', '5.2000', '4.5000', 100, 10000, 'backup'],
        ];

        $services = [];

        foreach ($rows as [$platform, $category, $name, $cost, $price, $min, $max, $panelKey]) {
            $services[] = BotService::withoutTenantScope()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                [
                    'panel_id' => $panels[$panelKey]->id,
                    'provider_service_id' => (string) random_int(1000, 9999),
                    'platform' => $platform,
                    'category' => $category,
                    'unit_label' => $category === 'Views' ? 'Views' : $category,
                    'cost_price' => $cost,
                    'my_price' => $price,
                    'min_quantity' => $min,
                    'max_quantity' => $max,
                    'status' => BotService::ACTIVE,
                    'last_synced_at' => now()->subHours(6),
                    'synced_cost_price' => $cost,
                ],
            );
        }

        return $services;
    }

    /**
     * The numbers the bots answer on, and a gateway customers can pay through.
     *
     * Credentials are obvious placeholders. They are never called — nothing in
     * a seeded account reaches the network — but a demo database is copied
     * around, and a value that looks like a key invites someone to try it.
     */
    private function connections(Tenant $tenant): void
    {
        foreach ([
            ['255700000001', 'order'],
            ['255700000002', 'support'],
        ] as [$number, $botType]) {
            TenantWhatsApp::withoutTenantScope()->updateOrCreate(
                ['phone_number_id' => 'demo-'.$botType.'-number'],
                [
                    'tenant_id' => $tenant->id,
                    'source' => 'own',
                    'cloud_api_token_enc' => 'demo-token-not-a-real-credential',
                    'waba_id' => 'demo-waba-'.$botType,
                    'verify_token' => 'demo-verify-'.$botType,
                    'display_number' => $number,
                    'status' => 'active',
                    'bot_type' => $botType,
                ],
            );
        }

        TenantPaymentGateway::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'gateway' => 'snippe'],
            [
                'api_key_enc' => 'demo-key-not-a-real-credential',
                'webhook_secret_enc' => 'demo-secret-not-a-real-credential',
                'status' => 'active',
                'is_default' => true,
            ],
        );
    }

    /**
     * Sixty days of customers, orders and top-ups.
     *
     * Seeded from a fixed sequence rather than random dates so the charts look
     * the same on every rebuild — a demo that reshuffles itself is impossible
     * to talk anyone through, and a screenshot taken today stops matching what
     * is on screen tomorrow.
     *
     * Every order carries `charge`, because that is what the profit screens
     * read; an order without it is excluded from them by design, and a demo
     * built from those would show a P&L of nothing.
     *
     * @param  array<int, BotService>  $services
     */
    private function trading(Tenant $tenant, array $services): void
    {
        $names = [
            'Amina J.', 'Baraka M.', 'Chausiku R.', 'Daudi K.', 'Eliza N.',
            'Faraja S.', 'Given P.', 'Hawa T.', 'Imani L.', 'Juma A.',
            'Kesi W.', 'Lulu B.', 'Mwajuma H.', 'Neema C.', 'Omary D.',
            'Pendo E.', 'Rehema F.', 'Salma G.', 'Tumaini I.', 'Upendo Z.',
        ];

        $customers = [];

        foreach ($names as $index => $name) {
            $customers[] = BotCustomer::withoutTenantScope()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'phone' => '2557001'.str_pad((string) $index, 5, '0', STR_PAD_LEFT)],
                [
                    'name' => $name,
                    'lang' => $index % 3 === 0 ? 'sw' : 'en',
                    'country' => 'TZ',
                    'balance' => number_format((($index * 7) % 40) + 1.5, 2, '.', ''),
                    'total_spent' => number_format((($index * 13) % 180) + 20, 2, '.', ''),
                    'first_deposit_done' => true,
                    'last_seen_at' => now()->subDays($index % 14),
                    'created_at' => now()->subDays(self::HISTORY_DAYS - ($index % 30)),
                ],
            );
        }

        $serviceCount = count($services);
        $customerCount = count($customers);

        // Two orders a day, alternating through the catalogue. Enough to fill
        // the 14-day charts, the 30-day KPIs, and the prior period they are
        // compared against.
        for ($day = self::HISTORY_DAYS; $day >= 0; $day--) {
            for ($slot = 0; $slot < 2; $slot++) {
                $step = ($day * 2) + $slot;

                $service = $services[$step % $serviceCount];
                $customer = $customers[$step % $customerCount];

                $quantity = [100, 500, 1000, 2000, 5000][$step % 5];

                $amount = bcdiv(bcmul((string) $service->my_price, (string) $quantity, 4), '1000', 2);
                $charge = bcdiv(bcmul((string) $service->cost_price, (string) $quantity, 4), '1000', 4);

                $placedAt = now()->subDays($day)->setTime(9 + ($slot * 6), ($step % 60));

                // Most complete; a few are still running and one in twelve
                // failed, so the status mix has all three slices.
                $status = match (true) {
                    $step % 12 === 0 => 'canceled',
                    $day <= 2 => 'processing',
                    default => 'completed',
                };

                $order = BotOrder::withoutTenantScope()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'provider_order_id' => 'DEMO-'.$step,
                    ],
                    [
                        'panel_id' => $service->panel_id,
                        'customer_phone' => $customer->phone,
                        'customer_id' => $customer->id,
                        'service_id' => $service->provider_service_id,
                        'service_name' => $service->name,
                        'link' => 'https://instagram.com/demo_'.$step,
                        'quantity' => $quantity,
                        'amount' => $amount,
                        'charge' => $charge,
                        'payment_status' => $status === 'canceled' ? 'failed' : 'paid',
                        'paid_from' => 'wallet',
                        'status' => $status,
                    ],
                );

                $order->forceFill([
                    'created_at' => $placedAt,
                    'updated_at' => $placedAt,
                ])->save();
            }
        }

        // Wallet top-ups — the dashboard's revenue line reads these, not the
        // orders. Roughly one every other day, so the trend has shape.
        for ($day = self::HISTORY_DAYS; $day >= 0; $day -= 2) {
            $customer = $customers[$day % $customerCount];
            $amount = number_format(5 + (($day * 3) % 45), 2, '.', '');
            $paidAt = now()->subDays($day)->setTime(11, $day % 60);

            $payment = BotPayment::withoutTenantScope()->updateOrCreate(
                ['transaction_ref' => 'DEMO-TOPUP-'.$day],
                [
                    'tenant_id' => $tenant->id,
                    'type' => 'wallet_topup',
                    'customer_id' => $customer->id,
                    'gateway' => 'snippe',
                    'amount' => $amount,
                    // One recent top-up left pending, so the payments screen
                    // shows a row that is still waiting on a gateway.
                    'status' => $day === 0 ? 'pending' : 'success',
                ],
            );

            $payment->forceFill(['created_at' => $paidAt, 'updated_at' => $paidAt])->save();
        }
    }

    /** Two paid, one still waiting on a gateway. */
    private function invoices(Tenant $tenant): void
    {
        $rows = [
            ['SUB-DEMO00000001', 'cryptomus', '34.00', '0.00', 2, 'success', 'order_bot', 62],
            ['SUB-DEMO00000002', 'cryptomus', '45.90', '5.10', 3, 'success', 'order_bot', 30],
            ['SUB-DEMO00000003', 'nowpayments', '17.00', '0.00', 1, 'pending', 'support_bot', 0],
        ];

        foreach ($rows as [$ref, $gateway, $amount, $credit, $months, $status, $service, $daysAgo]) {
            $payment = SubscriptionPayment::withoutTenantScope()->firstOrCreate(
                ['transaction_ref' => $ref],
                [
                    'tenant_id' => $tenant->id,
                    'gateway' => $gateway,
                    'amount' => $amount,
                    'credit_applied' => $credit,
                    'currency' => 'USD',
                    'months' => $months,
                    'items' => [['type' => 'service', 'key' => $service, 'months' => $months]],
                    'status' => $status,
                ],
            );

            $payment->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }
    }
}
