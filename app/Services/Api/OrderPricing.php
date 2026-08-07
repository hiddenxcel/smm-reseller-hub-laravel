<?php

namespace App\Services\Api;

use App\Models\BotService;

/**
 * What an order costs the customer.
 *
 * Prices are quoted per 1,000 units, which is the SMM convention throughout
 * this app and every panel it talks to. The arithmetic is bcmath on strings
 * for the reason set out in PricingEngine: at four decimal places a float
 * rounds in ways that show up in someone's wallet.
 *
 * This deliberately matches OrderBotHandler::costOf — the same service at the
 * same quantity must cost the same whether it was ordered over WhatsApp or
 * over the API, and a customer who can compare the two will.
 */
final class OrderPricing
{
    public static function charge(BotService $service, int $quantity): string
    {
        return bcdiv(
            bcmul((string) $service->my_price, (string) $quantity, 4),
            '1000',
            2,
        );
    }
}
