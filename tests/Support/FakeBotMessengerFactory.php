<?php

namespace Tests\Support;

use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotMessenger;
use App\Services\Bots\BotMessengerFactory;

/**
 * Hands out one shared FakeBotMessenger so a test can inspect everything the
 * router sent, whoever asked for it.
 */
class FakeBotMessengerFactory extends BotMessengerFactory
{
    public function __construct(public FakeBotMessenger $messenger = new FakeBotMessenger) {}

    public function forWhatsApp(TenantWhatsApp $whatsapp, Tenant $tenant): BotMessenger
    {
        return $this->messenger;
    }
}
