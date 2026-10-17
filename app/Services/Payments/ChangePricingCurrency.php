<?php

namespace App\Services\Payments;

use App\Models\BotService;
use App\Models\ServicePriceHistory;
use App\Models\Tenant;
use App\Services\Bots\BotSettings;
use App\Services\Catalogue\PricingEngine;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Moving a reseller's shop from one currency to another.
 *
 * Two different things get called "changing currency", and picking the wrong
 * one ruins a price list:
 *
 *  - RELABEL: the numbers are already in the new currency, only the label was
 *    wrong. A Kenyan panel reports costs in shillings; the shop showed them
 *    as dollars. Multiplying those by a rate would make every price 129 times
 *    too high. Nothing but the label changes.
 *  - CONVERT: the numbers are in the old currency and must be re-expressed in
 *    the new one at today's rate — prices, costs, wallets, orders and
 *    payments together. Wallets and orders have to move with the prices: a
 *    refund credits the order's own amount, and a wallet left at "10" would
 *    be read as ten shillings instead of ten dollars.
 *
 * Everything runs in one transaction, so a failure part-way leaves the shop
 * exactly as it was.
 */
class ChangePricingCurrency
{
    public const RELABEL = 'relabel';

    public const CONVERT = 'convert';

    /**
     * What a switch would do, without doing it.
     *
     * @return array{from: string, to: string, factor: string, services: int, customers: int, walletTotal: string, orders: int, sample: list<array{name: string, from: string, to: string}>}
     */
    public function preview(Tenant $tenant, string $to): array
    {
        $from = PricingCurrency::for($tenant);
        $factor = $this->factor($from, $to);

        $services = BotService::withoutTenantScope()->where('tenant_id', $tenant->id);

        return [
            'from' => $from,
            'to' => $to,
            'factor' => $factor,
            'services' => (clone $services)->count(),
            'customers' => DB::table('bot_customers')->where('tenant_id', $tenant->id)->count(),
            'walletTotal' => bcadd((string) DB::table('bot_customers')->where('tenant_id', $tenant->id)->sum('balance'), '0', 2),
            'orders' => DB::table('bot_orders')->where('tenant_id', $tenant->id)->count(),
            'sample' => (clone $services)->orderBy('id')->limit(4)->get()
                ->map(fn (BotService $service) => [
                    'name' => $service->name,
                    'from' => (string) $service->my_price,
                    'to' => bcadd(bcmul((string) $service->my_price, $factor, 8), '0', 4),
                ])
                ->all(),
        ];
    }

    /**
     * @throws \RuntimeException when the switch cannot be made safely
     */
    public function apply(Tenant $tenant, string $to, string $mode): void
    {
        if (! in_array($mode, [self::RELABEL, self::CONVERT], true)) {
            throw new \RuntimeException('Unknown way of changing currency.');
        }

        DB::transaction(function () use ($tenant, $to, $mode) {
            // Locked so two tabs cannot convert the same shop twice.
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->first();
            $from = PricingCurrency::for($locked);

            if ($from === $to) {
                return;
            }

            if ($mode === self::CONVERT) {
                $this->refuseWhilePaymentsAreOpen($locked);
                $this->convertMoney($locked, $this->factor($from, $to), $from, $to);
            }

            $settings = BotSettings::for((int) $locked->id, 'order');
            Arr::set($settings, 'shop.currency', $to);

            if ($mode === self::CONVERT && Arr::get($settings, 'shop.min_topup') !== null) {
                Arr::set($settings, 'shop.min_topup', round(
                    (float) bcmul((string) Arr::get($settings, 'shop.min_topup'), $this->factor($from, $to), 8),
                    2,
                ));
            }

            BotSettings::save((int) $locked->id, 'order', $settings);

            $locked->update([
                'pricing_currency' => $to,
                'pricing_currency_changed_at' => now(),
            ]);

            $tenant->refresh();
        });
    }

    /** How many units of $to one unit of $from buys, through the dollar. */
    public function factor(string $from, string $to): string
    {
        $fromRate = ExchangeRates::perUsd($from);
        $toRate = ExchangeRates::perUsd($to);

        if ($fromRate === null || $toRate === null) {
            throw new \RuntimeException("No exchange rate for {$from} or {$to}.");
        }

        return bcdiv(number_format($toRate, 8, '.', ''), number_format($fromRate, 8, '.', ''), 10);
    }

    /**
     * A customer paying right now would be charged in one currency and
     * credited in another. Better to ask the reseller to wait a few minutes.
     */
    private function refuseWhilePaymentsAreOpen(Tenant $tenant): void
    {
        $open = DB::table('bot_payments')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subHours(2))
            ->exists();

        if ($open) {
            throw new \RuntimeException('A customer payment is still in progress. Try again in a few minutes.');
        }
    }

    private function convertMoney(Tenant $tenant, string $factor, string $from, string $to): void
    {
        $engine = PricingEngine::for($tenant);
        $note = "Currency changed {$from} to {$to} at {$factor}";

        BotService::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($services) use ($engine, $factor, $note) {
                foreach ($services as $service) {
                    $engine->setPrice(
                        $service,
                        bcmul((string) $service->my_price, $factor, 8),
                        ServicePriceHistory::CURRENCY_SWITCH,
                        $note,
                        $service->cost_price === null ? null : bcadd(bcmul((string) $service->cost_price, $factor, 8), '0', 4),
                    );
                }
            });

        // The factor is built from validated decimals, but it goes into SQL
        // as a literal, so it is checked rather than trusted.
        if (! preg_match('/^\d{1,12}\.\d{1,10}$/', $factor)) {
            throw new \RuntimeException('Invalid exchange factor.');
        }

        $times = fn (string $column, int $places) => DB::raw("round({$column} * {$factor}, {$places})");

        DB::table('bot_customers')->where('tenant_id', $tenant->id)->update([
            'balance' => $times('balance', 2),
            'total_spent' => $times('total_spent', 2),
            'referral_earnings' => $times('referral_earnings', 2),
        ]);

        DB::table('bot_orders')->where('tenant_id', $tenant->id)->update([
            'amount' => $times('amount', 2),
            'charge' => $times('charge', 4),
            'refunded_amount' => $times('refunded_amount', 2),
        ]);

        DB::table('bot_payments')->where('tenant_id', $tenant->id)->update([
            'amount' => $times('amount', 2),
        ]);

        // Only the rules that are an amount of money. A percentage or a
        // multiplier means the same in any currency.
        DB::table('pricing_rules')->where('tenant_id', $tenant->id)->where('mode', 'fixed')->update([
            'amount' => $times('amount', 4),
        ]);

        DB::table('pricing_rules')->where('tenant_id', $tenant->id)->update([
            'min_profit' => $times('min_profit', 4),
            'max_profit' => $times('max_profit', 4),
            'round_to' => $times('round_to', 4),
        ]);
    }
}
