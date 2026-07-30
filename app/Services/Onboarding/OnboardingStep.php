<?php

namespace App\Services\Onboarding;

/**
 * The path from signing up to a working shop.
 *
 * Ordered by dependency: services can only be imported once a panel is
 * connected, and there is nothing to test until WhatsApp is attached.
 */
enum OnboardingStep: string
{
    case ConnectPanel = 'panel';
    case ImportServices = 'services';
    case ConnectWhatsApp = 'whatsapp';
    case SetupPayments = 'payments';
    case TestBot = 'test';

    public function title(): string
    {
        return match ($this) {
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
            self::ConnectPanel => 'Paste your panel URL and admin API key — we work out the rest.',
            self::ImportServices => 'Pull in your services and set your own prices.',
            self::ConnectWhatsApp => 'Use your own Meta number, or rent one from us.',
            self::SetupPayments => 'Add a gateway so your customers can top up their wallets.',
            self::TestBot => 'Message your own bot and place a test order.',
        };
    }

    /**
     * Payments can be added later — a reseller can run on manually credited
     * wallets at first — so only these four block going live.
     */
    public function isRequired(): bool
    {
        return $this !== self::SetupPayments;
    }

    /** @return array<int, self> */
    public static function ordered(): array
    {
        return self::cases();
    }
}
