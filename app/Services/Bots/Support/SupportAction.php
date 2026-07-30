<?php

namespace App\Services\Bots\Support;

/**
 * The Quick Menu options. The case value is the number the customer types, so
 * reordering these changes what an existing customer's muscle memory selects.
 */
enum SupportAction: string
{
    case Refill = '1';
    case SpeedUp = '2';
    case Cancel = '3';
    case Partial = '4';
    case Human = '5';
    case Status = '6';
    case TopupIssue = '7';
    case Faq = '8';

    public function label(): string
    {
        return match ($this) {
            self::Refill => '1️⃣ Refill',
            self::SpeedUp => '2️⃣ Speed Up',
            self::Cancel => '3️⃣ Cancel',
            self::Partial => '4️⃣ Partial / Fake Comp',
            self::Human => '5️⃣ 👤 Talk to a Human Agent',
            self::Status => '6️⃣ 📦 Order Status',
            self::TopupIssue => '7️⃣ 💸 Balance Top-Up Issue',
            self::Faq => '8️⃣ ❓ AI FAQ',
        };
    }

    /** Which actions cannot proceed without an order to act on. */
    public function needsOrderId(): bool
    {
        return match ($this) {
            self::Refill, self::SpeedUp, self::Cancel, self::Partial, self::Status => true,
            self::Human, self::TopupIssue, self::Faq => false,
        };
    }

    /**
     * Actions the reseller can switch off in their bot settings. The others
     * are always available — a customer must never be unable to reach a human.
     */
    public function toggleKey(): ?string
    {
        return match ($this) {
            self::Refill => 'refill',
            self::Status => 'status',
            self::Cancel => 'cancel',
            self::SpeedUp => 'speedup',
            default => null,
        };
    }

    /** Does this action need to reach the reseller's panel to do anything? */
    public function needsPanel(): bool
    {
        return match ($this) {
            self::Refill, self::Cancel, self::Status => true,
            default => false,
        };
    }
}
