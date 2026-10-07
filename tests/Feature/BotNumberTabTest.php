<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A bot's number is managed from the bot itself, not only from setup: each
 * bot's Number tab shows its own number and nobody else's.
 */
class BotNumberTabTest extends TestCase
{
    use RefreshDatabase;

    private function number(Tenant $tenant, string $bot, string $id): TenantWhatsApp
    {
        return TenantWhatsApp::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'phone_number_id' => $id,
            'display_number' => '+2557'.$id,
            'bot_type' => $bot,
            'source' => 'own',
            'status' => 'active',
            'cloud_api_token_enc' => 'x',
        ]);
    }

    public function test_each_bot_lists_only_its_own_number(): void
    {
        $tenant = Tenant::factory()->create();
        $this->number($tenant, 'order', '111');
        $this->number($tenant, 'support', '222');

        $this->actingAs($tenant)->get(route('order-bot', 'number'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('OrderBot/Index')
                ->where('numbers.bot', 'order')
                ->has('numbers.numbers', 1)
                ->where('numbers.numbers.0.phone_number_id', '111'));

        $this->actingAs($tenant)->get(route('support-bot', 'number'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SupportBot/Index')
                ->where('numbers.bot', 'support')
                ->where('numbers.numbers.0.phone_number_id', '222'));
    }

    public function test_disconnecting_from_the_bot_page_stays_on_the_bot_page(): void
    {
        $tenant = Tenant::factory()->create();
        $row = $this->number($tenant, 'support', '333');

        $this->actingAs($tenant)
            ->from(route('support-bot', 'number'))
            ->delete(route('onboarding.whatsapp.disconnect', $row->id))
            ->assertRedirect(route('support-bot', 'number'));

        $this->assertDatabaseMissing('tenant_whatsapp', ['phone_number_id' => '333']);
    }
}
