<?php

namespace App\Services\Simulator;

use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use App\Services\Bots\BotHandlerFactory;
use App\Services\Bots\BotLang;
use App\Services\Bots\BotSettings;
use App\Services\Bots\BotSimulation;
use Illuminate\Support\Facades\DB;

/**
 * Runs the real bot handlers against a pretend customer, and leaves no trace.
 *
 * The handlers are not re-implemented or scripted: a message goes through
 * exactly the code a customer's would. What makes that safe is the shape of
 * each call:
 *
 *   1. open a database transaction,
 *   2. put the pretend customer, their conversation and their earlier orders
 *      back as they were at the end of the last message,
 *   3. hand the message to the handler,
 *   4. read the new state out, and
 *   5. roll the transaction back.
 *
 * Nothing the handler writes — the debited wallet, the order, the ticket, the
 * sample services — survives step 5, so the reseller's real orders, revenue
 * and customer list are never touched by someone trying the bot out. The state
 * that has to carry from one message to the next travels in the caller's
 * session instead.
 *
 * What a rollback cannot undo is a call to something outside the database. The
 * handlers check BotSimulation for those: the panel and the payment gateways
 * are not contacted.
 */
class BotSimulator
{
    public const BOTS = ['order', 'support'];

    /** What a fresh pretend customer has to spend. */
    public const STARTING_BALANCE = '10.00';

    /** Orders remembered between messages, so "track my orders" has something to show. */
    private const REMEMBERED_ORDERS = 10;

    /** Clearly invented panel order numbers, so nobody mistakes them for real ones. */
    private const ORDER_NUMBER_BASE = 90000;

    public function __construct(private BotHandlerFactory $handlers) {}

    public static function freshState(): array
    {
        return [
            'balance' => self::STARTING_BALANCE,
            'spent' => '0',
            'lang' => null,
            'orders' => [],
            'bots' => [],
        ];
    }

    public static function phoneFor(Tenant $tenant): string
    {
        return 'sim:'.$tenant->id;
    }

    /**
     * @param  array<string, mixed>  $state  what the last call returned
     * @return array{events: array<int, array>, state: array<string, mixed>, sample: bool}
     */
    public function send(Tenant $tenant, string $bot, string $text, array $state): array
    {
        if (! in_array($bot, self::BOTS, true)) {
            throw new \InvalidArgumentException("Unknown bot '{$bot}'");
        }

        $state = array_replace(self::freshState(), $state);
        $phone = self::phoneFor($tenant);
        $messenger = new WebSimMessenger($phone);

        DB::beginTransaction();

        try {
            $sample = $this->ensureServices($tenant);
            $customer = $this->restoreCustomer($tenant, $phone, $state);
            $this->restoreOrders($tenant, $customer, $state['orders']);
            $this->restoreConversation($tenant, $phone, $bot, $state['bots'][$bot] ?? null);

            BotSimulation::run(function () use ($bot, $tenant, $messenger, $phone, $text) {
                $this->handlers->for($bot, $tenant, $messenger)->handle($phone, $text);
            });

            $state = $this->capture($tenant, $phone, $bot, $state);
        } finally {
            DB::rollBack();
        }

        return ['events' => $messenger->events(), 'state' => $state, 'sample' => $sample];
    }

    /**
     * A reseller who has not imported anything yet still deserves a bot that
     * has something to sell. The sample catalogue exists only inside the
     * transaction, so it can never leak into a real shop or be mistaken for the
     * "services imported" step.
     *
     * @return bool whether the sample catalogue is what the bot is showing
     */
    private function ensureServices(Tenant $tenant): bool
    {
        $has = BotService::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', BotService::ACTIVE)
            ->exists();

        if ($has) {
            return false;
        }

        $sample = [
            ['Instagram', 'Followers', 'Instagram Followers — Real, 30 day refill', 'Followers', 2.50, 10, 50000],
            ['Instagram', 'Followers', 'Instagram Followers — Fast', 'Followers', 1.80, 10, 20000],
            ['Instagram', 'Likes', 'Instagram Likes — Instant', 'Likes', 0.90, 10, 10000],
            ['TikTok', 'Followers', 'TikTok Followers — Real', 'Followers', 3.20, 10, 30000],
            ['TikTok', 'Views', 'TikTok Views — Instant', 'Views', 0.15, 100, 1000000],
            ['YouTube', 'Views', 'YouTube Views — High retention', 'Views', 1.40, 100, 100000],
            ['YouTube', 'Subscribers', 'YouTube Subscribers', 'Subscribers', 9.00, 10, 5000],
            ['Telegram', 'Members', 'Telegram Channel Members', 'Members', 2.10, 50, 50000],
        ];

        foreach ($sample as $index => [$platform, $category, $name, $unit, $price, $min, $max]) {
            BotService::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'panel_id' => null,
                'provider_service_id' => 'sample-'.($index + 1),
                'platform' => $platform,
                'category' => $category,
                'name' => $name,
                'unit_label' => $unit,
                'my_price' => $price,
                'min_quantity' => $min,
                'max_quantity' => $max,
                'link_instructions' => 'Paste the link to your profile or post.',
                'status' => BotService::ACTIVE,
                'sort_order' => $index,
                'featured' => $index === 0,
            ]);
        }

        return true;
    }

    private function restoreCustomer(Tenant $tenant, string $phone, array $state): BotCustomer
    {
        return BotCustomer::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'phone' => $phone,
            'name' => 'You',
            // Not null in the table; a new customer takes the shop's language
            // exactly as a real one does.
            'lang' => $state['lang'] ?? BotSettings::for($tenant->id, 'order')['shop']['lang'] ?? BotLang::DEFAULT,
            'balance' => $state['balance'],
            'total_spent' => $state['spent'],
        ]);
    }

    /** @param array<int, array<string, mixed>> $orders */
    private function restoreOrders(Tenant $tenant, BotCustomer $customer, array $orders): void
    {
        foreach ($orders as $order) {
            BotOrder::withoutTenantScope()->create([
                ...$order,
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'customer_phone' => $customer->phone,
            ]);
        }
    }

    /** @param array{state: string, context: array}|null $saved */
    private function restoreConversation(Tenant $tenant, string $phone, string $bot, ?array $saved): void
    {
        if ($saved === null) {
            return;
        }

        BotConversation::put($tenant->id, $phone, $bot, $saved['state'], $saved['context'] ?? []);
    }

    /** Read back what has to survive the rollback. */
    private function capture(Tenant $tenant, string $phone, string $bot, array $state): array
    {
        $conversation = BotConversation::current($tenant->id, $phone, $bot);

        $state['bots'][$bot] = $conversation === null
            ? null
            : ['state' => $conversation->state, 'context' => $conversation->context ?? []];

        $customer = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('phone', $phone)
            ->first();

        if ($customer !== null) {
            $state['balance'] = (string) $customer->balance;
            $state['spent'] = (string) $customer->total_spent;
            $state['lang'] = $customer->lang;
        }

        $orders = BotOrder::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', $phone)
            ->orderBy('id')
            ->get();

        $state['orders'] = $orders
            ->take(-self::REMEMBERED_ORDERS)
            ->values()
            ->map(fn (BotOrder $order, int $index) => [
                'provider_order_id' => $order->provider_order_id
                    ?: (string) (self::ORDER_NUMBER_BASE + $orders->count() + $index),
                'service_id' => $order->service_id,
                'service_name' => $order->service_name,
                'link' => $order->link,
                'quantity' => $order->quantity,
                'amount' => (string) $order->amount,
                'payment_status' => 'paid',
                'paid_from' => 'wallet',
                'status' => $order->status ?: 'pending',
            ])
            ->all();

        return $state;
    }
}
