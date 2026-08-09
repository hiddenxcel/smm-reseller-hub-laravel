<?php

namespace Database\Seeders;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\GuaranteeRule;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
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
 * days from expiry, one bot still in sandbox, and a customer mid-handoff are
 * each the case the UI was built to handle — an account with none of them
 * shows empty panels and proves nothing.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const EMAIL = 'demo@wizard.test';

    private const PASSWORD = '12345678';

    /** The customer who asked for a human and is waiting on a reply. */
    private const WAITING = '255700111222';

    private const SETTLED = '255700333444';

    public function run(): void
    {
        $tenant = $this->tenant();

        $this->conversation($tenant);
        $this->guaranteeRules($tenant);
        $this->subscriptions($tenant);
        $this->invoices($tenant);

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

    /** Active but nearly expired, plus one still in sandbox. */
    private function subscriptions(Tenant $tenant): void
    {
        Subscription::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'service_key' => ServiceKey::OrderBot->value],
            [
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subMonths(2),
                'ends_at' => now()->addDays(4),
                'auto_renew' => false,
            ],
        );

        Subscription::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'service_key' => ServiceKey::SupportBot->value],
            [
                'status' => SubscriptionStatus::Sandbox,
                'starts_at' => now()->subDays(5),
                'ends_at' => null,
                'auto_renew' => false,
            ],
        );
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
