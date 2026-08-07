<?php

namespace App\Services\Ai;

use App\Enums\ServiceKey;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantAi;

/**
 * The one way a bot asks the AI a question.
 *
 * Both bots go through here so the gate is checked in one place. Three things
 * must all hold before a reseller's key is ever used:
 *
 *   1. They are subscribed to the AI Chat add-on. It is sold separately.
 *   2. They have stored a DeepSeek key. It is theirs and they are billed for it.
 *   3. They have not paused it.
 *
 * Any of those failing is not an error — it is a shop that does not offer AI
 * support, and the caller sends the customer to the menu instead.
 */
class AiAnswers
{
    /**
     * Whether this reseller's bots can answer with AI at all.
     *
     * Cheap enough to call before showing a menu option, which is where it is
     * used — an option that always answers "not available" is worse than no
     * option.
     */
    public function isAvailable(int $tenantId): bool
    {
        return Subscription::isServiceActive($tenantId, ServiceKey::AiChat)
            && (bool) TenantAi::forTenant($tenantId)?->isReady();
    }

    /**
     * Answer a customer's question about this shop.
     *
     * @param  array<string, mixed>  $shop  the reseller's bot shop settings
     * @param  array<int, array{role: string, content: string}>  $history
     *                          earlier turns, so a follow-up like "and for TikTok?"
     *                          is understood
     * @return string|null null when AI is unavailable, or when DeepSeek failed —
     *                     the caller says something a customer can act on
     */
    public function answer(
        Tenant $tenant,
        string $question,
        array $shop = [],
        array $history = [],
    ): ?string {
        $tenantId = (int) $tenant->id;

        if (! Subscription::isServiceActive($tenantId, ServiceKey::AiChat)) {
            return null;
        }

        $ai = TenantAi::forTenant($tenantId);

        if ($ai === null || ! $ai->isReady()) {
            return null;
        }

        $answer = (new DeepSeekClient((string) $ai->deepseek_api_key_enc))->ask(
            system: ShopContext::for($tenant, $shop),
            question: $question,
            history: $history,
        );

        // Counted only when an answer came back. A failed call costs the
        // reseller nothing, so counting it would overstate their usage at
        // exactly the moment their bot looked broken.
        if ($answer !== null) {
            $ai->recordAnswer();
        }

        return $answer;
    }
}
