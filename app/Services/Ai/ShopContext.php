<?php

namespace App\Services\Ai;

use App\Models\BotService;
use App\Models\Tenant;
use App\Services\Bots\BotLang;
use Illuminate\Support\Arr;

/**
 * The system prompt: who the assistant is, and everything it is allowed to
 * claim.
 *
 * This is what separates a useful shop assistant from a general chatbot that
 * invents prices. Every fact a customer could ask about — which services
 * exist, what they cost, the minimum order, how long delivery takes — is
 * written into the prompt from the reseller's own catalogue, and the rules
 * below forbid answering beyond it.
 *
 * The failure worth designing against is not a wrong answer but a confident
 * one: a customer told "yes, we do TikTok views at $1" by an assistant that
 * guessed will hold the reseller to it.
 */
class ShopContext
{
    /**
     * How many services to describe.
     *
     * A large catalogue would otherwise fill the prompt — and the reseller's
     * bill — with services nobody asked about. The most prominent ones answer
     * the overwhelming majority of questions, and the assistant is told to
     * send anyone asking beyond them to the ordering menu, which lists
     * everything.
     */
    private const MAX_SERVICES = 40;

    /** @param array<string, mixed> $shop the reseller's bot shop settings */
    public static function for(Tenant $tenant, array $shop): string
    {
        $currency = (string) ($shop['currency'] ?? 'USD');
        $locale = BotLang::normalize($shop['lang'] ?? null);

        return implode("\n\n", array_filter([
            self::identity($tenant),
            self::rules($locale),
            self::catalogue((int) $tenant->id, $currency),
            self::ordering($shop, $currency),
        ]));
    }

    private static function identity(Tenant $tenant): string
    {
        return "You are the customer support assistant for {$tenant->business_name}, "
            .'a social media marketing (SMM) shop that sells followers, likes, views '
            .'and similar engagement services. You answer customers on WhatsApp.';
    }

    /**
     * The constraints, stated as hard rules.
     *
     * Ordered by how much damage breaking each one does: inventing a price or
     * a service the shop does not sell is the worst, because the customer
     * arrives expecting it.
     */
    private static function rules(string $locale): string
    {
        $language = BotLang::NAMES[$locale] ?? 'English';

        return implode("\n", [
            'RULES — follow these exactly:',
            "1. Reply in {$language} unless the customer clearly writes in another language, then use theirs.",
            '2. Only discuss services listed below. If asked about anything not listed, say the shop does not offer it.',
            '3. Never invent or estimate a price, a delivery time, or a quantity. If a number is not listed below, say you are not sure and suggest they check the order menu.',
            '4. You cannot place orders, check an order status, see a wallet balance, or process payments. For any of those, tell the customer to send *menu*.',
            '5. Never ask for or repeat passwords, card numbers, or account login details. Customers only ever give a public profile link.',
            '6. Keep replies under 60 words. This is a chat, not an article.',
            '7. If you cannot answer, say so plainly and suggest *menu* to reach a human.',
        ]);
    }

    /**
     * What the shop sells, at the reseller's own prices.
     *
     * Only active services: a paused one cannot be ordered, and a hidden one
     * was deliberately taken off the shelf. Quoting either invites a customer
     * to ask for something the bot will then refuse.
     */
    private static function catalogue(int $tenantId, string $currency): string
    {
        $services = BotService::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', BotService::ACTIVE)
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->limit(self::MAX_SERVICES)
            ->get();

        if ($services->isEmpty()) {
            return 'This shop has no services listed yet. Tell any customer asking '
                .'about services to send *menu* and speak to a human.';
        }

        $lines = $services->map(function (BotService $service) use ($currency) {
            $price = rtrim(rtrim(number_format((float) $service->my_price, 4, '.', ''), '0'), '.');
            $unit = $service->unit_label ?: 'per 1000';

            $line = "- {$service->platform} — {$service->name}: {$price} {$currency} {$unit}";

            if ($service->min_quantity) {
                $line .= ", minimum {$service->min_quantity}";
            }

            if ($service->max_quantity) {
                $line .= ", maximum {$service->max_quantity}";
            }

            return $line;
        })->implode("\n");

        return "SERVICES THIS SHOP SELLS (these prices are exact — never round or estimate):\n"
            .$lines;
    }

    /** How a customer actually buys, since the assistant cannot sell to them. */
    private static function ordering(array $shop, string $currency): string
    {
        $minTopup = Arr::get($shop, 'min_topup', 1);

        return implode("\n", array_filter([
            'HOW ORDERING WORKS:',
            '- The customer sends *menu* to open the ordering menu and place an order there.',
            '- Orders are paid from a wallet balance, topped up inside that menu.',
            "- The minimum top-up is {$minTopup} {$currency}.",
            '- A customer gives a public profile or post link, never a password.',
        ]));
    }
}
