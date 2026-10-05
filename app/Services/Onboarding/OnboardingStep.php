<?php

namespace App\Services\Onboarding;

/**
 * The path from signing up to a working shop.
 *
 * Ordered by dependency: services can only be imported once a panel is
 * connected, and there is nothing to test until WhatsApp is attached.
 *
 * Trying the bot comes first and depends on nothing. People decide whether to
 * set a shop up by seeing it work, and every other step asks for something
 * they may not have to hand — a panel key, a Meta number — before showing them
 * anything at all.
 */
enum OnboardingStep: string
{
    case TryBot = 'try';
    case ConnectPanel = 'panel';
    case ImportServices = 'services';
    case ConnectWhatsApp = 'whatsapp';
    case SetupPayments = 'payments';
    case TestBot = 'test';

    public function title(): string
    {
        return match ($this) {
            self::TryBot => 'Try your bot',
            self::ConnectPanel => 'Connect your panel',
            self::ImportServices => 'Import your services',
            self::ConnectWhatsApp => 'Connect WhatsApp',
            self::SetupPayments => 'Set up payments',
            self::TestBot => 'Test your bot',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TryBot => 'Chat with a sample shop on a WhatsApp screen — nothing to set up.',
            self::ConnectPanel => 'Paste your panel URL and API key — we work out the rest.',
            self::ImportServices => 'Pull in your services and set your own prices.',
            self::ConnectWhatsApp => 'Use your own Meta number, or rent one from us.',
            self::SetupPayments => 'Add a gateway so your customers can top up their wallets.',
            self::TestBot => 'Place a test order with your own services, then go live.',
        };
    }

    /**
     * Payments can be added later — a reseller can run on manually credited
     * wallets at first — and trying the bot is for the reseller's benefit, not
     * the shop's, so only the other four block going live.
     */
    public function isRequired(): bool
    {
        return ! in_array($this, [self::SetupPayments, self::TryBot], true);
    }

    /** @return array<int, self> */
    public static function ordered(): array
    {
        return self::cases();
    }
}
