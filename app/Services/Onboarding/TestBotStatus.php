<?php

namespace App\Services\Onboarding;

use App\Models\BotMessage;
use App\Models\BotOrder;
use App\Services\Bots\BotSettings;
use Illuminate\Support\Arr;

/**
 * What we can actually observe about a reseller's bot: whether a message ever
 * reached it, whether it answered, and whether an order came out the far end.
 *
 * The wizard screen and the go-live check both read this, so the page can
 * never show a green tick for something go-live would then reject.
 */
class TestBotStatus
{
    public function __construct(private int $tenantId) {}

    public static function for(int $tenantId): self
    {
        return new self($tenantId);
    }

    /** Numbers the reseller registered to test from. */
    public function testNumbers(): array
    {
        return array_values(Arr::get(
            BotSettings::for($this->tenantId, 'order'),
            'shop.test_numbers',
            [],
        ));
    }

    public function messageReceived(): bool
    {
        return $this->messages()->where('direction', 'in')->exists();
    }

    /** The one that matters: the bot did something, not just heard something. */
    public function botHasReplied(): bool
    {
        return $this->messages()->where('direction', 'out')->exists();
    }

    public function orderPlaced(): bool
    {
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->exists();
    }

    /** The last few lines of the conversation, newest first. */
    public function recentMessages(int $limit = 8): array
    {
        return $this->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'direction', 'message', 'customer_phone', 'created_at'])
            ->map(fn (BotMessage $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'message' => $message->message,
                'phone' => $message->customer_phone,
                'at' => $message->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function toArray(): array
    {
        return [
            'testNumbers' => $this->testNumbers(),
            'messageReceived' => $this->messageReceived(),
            'botReplied' => $this->botHasReplied(),
            'orderPlaced' => $this->orderPlaced(),
            'recentMessages' => $this->recentMessages(),
        ];
    }

    private function messages()
    {
        return BotMessage::withoutTenantScope()->where('tenant_id', $this->tenantId);
    }
}
