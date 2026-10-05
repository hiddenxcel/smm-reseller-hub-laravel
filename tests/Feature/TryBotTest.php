<?php

namespace Tests\Feature;

use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The practice chat for a stranger: reachable without an account, and unable
 * to leave anything behind.
 */
class TryBotTest extends TestCase
{
    use RefreshDatabase;

    private function say(string $text, string $bot = 'order', array $extra = [])
    {
        return $this->postJson(route('try.send'), ['bot' => $bot, 'text' => $text, ...$extra]);
    }

    public function test_the_page_is_public(): void
    {
        $this->get(route('try'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Try')
                ->where('simulator.endpoint', route('try.send'))
                ->where('simulator.sendBusiness', true));
    }

    public function test_a_stranger_gets_the_real_menu_with_their_shop_name(): void
    {
        $response = $this->say('hi', extra: ['reset' => true, 'business' => 'Kuza Panel'])->assertOk();

        $response->assertJsonPath('events.0.type', 'list');
        $this->assertStringContainsString('Kuza Panel', $response->json('events.0.body'));
        $this->assertSame('main:new_order', $response->json('events.0.rows.0.id'));
    }

    public function test_the_shop_name_is_cleaned_before_it_is_used(): void
    {
        $response = $this->say('hi', extra: ['reset' => true, 'business' => '<b>Evil</b> *Shop* 😀']);

        $body = $response->json('events.0.body');
        $this->assertStringNotContainsString('<b>', $body);
        $this->assertStringNotContainsString('😀', $body);
        $this->assertStringContainsString('bEvilb Shop', $body);
    }

    public function test_a_missing_name_falls_back(): void
    {
        $this->say('hi', extra: ['reset' => true, 'business' => '   '])
            ->assertJsonPath('events.0.type', 'list');

        $this->assertStringContainsString('Your Shop', $this->say('hi', extra: ['reset' => true])->json('events.0.body'));
    }

    public function test_it_walks_a_whole_order_without_an_account(): void
    {
        $this->say('hi', extra: ['reset' => true]);
        $this->say('main:new_order');
        $category = $this->say('plat_Instagram')->json('events.0.rows.0.id');
        $service = $this->say($category)->json('events.0.rows.0.id');
        $this->say($service);
        $this->say('qty_100');
        $this->say('https://instagram.com/someone');
        $placed = $this->say('confirm_yes');

        $this->assertStringContainsString('placed', mb_strtolower(collect($placed->json('events'))->pluck('body')->implode(' ')));
        $this->assertLessThan(10.0, (float) $placed->json('balance'));
    }

    public function test_nothing_at_all_is_saved(): void
    {
        $tenants = Tenant::count();

        $this->say('hi', extra: ['reset' => true]);
        $this->say('main:profile');
        $this->say('hi', 'support');
        $this->say('5', 'support');

        $this->assertSame($tenants, Tenant::count());
        $this->assertSame(0, BotService::withoutTenantScope()->count());
        $this->assertSame(0, BotCustomer::withoutTenantScope()->count());
        $this->assertSame(0, BotConversation::withoutTenantScope()->count());
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_the_support_bot_works_too(): void
    {
        $response = $this->say('hi', 'support', ['reset' => true])->assertOk();

        $this->assertStringContainsString('Quick Menu', $response->json('events.0.body'));
    }

    public function test_an_unknown_bot_is_rejected(): void
    {
        $this->say('hi', 'admin')->assertUnprocessable();
    }

    public function test_it_is_rate_limited_per_address(): void
    {
        RateLimiter::clear('try-bot');

        $last = null;

        for ($i = 0; $i < 31; $i++) {
            $last = $this->say('hi');
        }

        $last->assertStatus(429);
    }

    public function test_the_page_is_in_the_sitemap(): void
    {
        $this->get(route('sitemap'))->assertOk()->assertSee(route('try'), false);
    }
}
