<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Billing\StartSandbox;
use App\Services\Bots\BotHandlerFactory;
use App\Services\Bots\BotRoute;
use App\Services\Bots\BotRouter;
use App\Services\Bots\BotSettings;
use App\Services\Bots\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBotHandler;
use Tests\Support\FakeBotMessengerFactory;
use Tests\Support\FakeOrderBotHandler;
use Tests\Support\FakeSupportBotHandler;
use Tests\TestCase;

/**
 * A new reseller can try the bot on their own phone before paying.
 *
 * The router test for this builds the sandbox subscription by hand, which is
 * how it passed while a real sign-up never produced one: every number a
 * reseller registered was then told "this service is currently paused". These
 * start from the sign-up form instead.
 */
class SandboxAtSignupTest extends TestCase
{
    use RefreshDatabase;

    private function register(): Tenant
    {
        $this->post(route('register'), [
            'business_name' => 'New Shop',
            'email' => 'new@example.com',
            'password' => 'password-that-is-long',
            'password_confirmation' => 'password-that-is-long',
        ]);

        return Tenant::where('email', 'new@example.com')->firstOrFail();
    }

    private function router(): BotRouter
    {
        FakeBotHandler::reset();

        $handlers = new BotHandlerFactory;
        $handlers->register('order', FakeOrderBotHandler::class);
        $handlers->register('support', FakeSupportBotHandler::class);

        return new BotRouter(new FakeBotMessengerFactory, $handlers);
    }

    private function message(TenantWhatsApp $number, string $from): InboundMessage
    {
        return new InboundMessage($number->phone_number_id, $from, 'hi', 'wamid.TEST');
    }

    public function test_signing_up_puts_both_bots_in_sandbox(): void
    {
        $tenant = $this->register();

        foreach ([ServiceKey::OrderBot, ServiceKey::SupportBot] as $service) {
            $this->assertTrue(Subscription::isSandbox($tenant->id, $service), "{$service->value} should be in sandbox");
            $this->assertFalse(Subscription::isServiceActive($tenant->id, $service), 'sandbox is not live');
        }
    }

    public function test_the_other_services_are_not_given_away(): void
    {
        $tenant = $this->register();

        foreach ([ServiceKey::AiTickets, ServiceKey::AiChat, ServiceKey::NumberRental] as $service) {
            $this->assertFalse(Subscription::isUsable($tenant->id, $service));
        }
    }

    public function test_a_new_resellers_own_test_number_gets_answered(): void
    {
        $tenant = $this->register();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();

        BotSettings::save($tenant->id, 'order', ['shop' => ['test_numbers' => ['255700000001']]]);

        $this->assertSame(
            BotRoute::HandledOrder,
            $this->router()->route($this->message($number, '255700000001')),
        );
    }

    public function test_a_stranger_is_still_refused_until_the_service_is_paid_for(): void
    {
        $tenant = $this->register();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();

        BotSettings::save($tenant->id, 'order', ['shop' => ['test_numbers' => ['255700000001']]]);

        $this->assertSame(
            BotRoute::GateLocked,
            $this->router()->route($this->message($number, '255700000099')),
        );
    }

    public function test_starting_sandbox_never_downgrades_a_paid_service(): void
    {
        $tenant = Tenant::factory()->create();
        Subscription::factory()->for($tenant)->active()->create(['service_key' => ServiceKey::OrderBot]);

        app(StartSandbox::class)($tenant);

        $order = Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)->forService(ServiceKey::OrderBot)->get();
        $this->assertCount(1, $order);
        $this->assertSame(SubscriptionStatus::Active, $order->first()->status);
    }

    public function test_it_can_run_twice_without_duplicating(): void
    {
        $tenant = Tenant::factory()->create();

        app(StartSandbox::class)($tenant);
        app(StartSandbox::class)($tenant);

        $this->assertSame(2, Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_backfill_fills_a_gap_and_touches_nothing_else(): void
    {
        $empty = Tenant::factory()->create();
        $paid = Tenant::factory()->create();
        Subscription::factory()->for($paid)->active()->create(['service_key' => ServiceKey::OrderBot]);

        (require base_path('database/migrations/2026_10_05_130000_backfill_sandbox_subscriptions.php'))->up();

        $this->assertTrue(Subscription::isSandbox($empty->id, ServiceKey::OrderBot));
        $this->assertTrue(Subscription::isSandbox($empty->id, ServiceKey::SupportBot));

        $this->assertTrue(Subscription::isServiceActive($paid->id, ServiceKey::OrderBot));
        $this->assertFalse(Subscription::isSandbox($paid->id, ServiceKey::OrderBot));
        $this->assertTrue(Subscription::isSandbox($paid->id, ServiceKey::SupportBot));

        // Running it again changes nothing.
        $before = Subscription::withoutTenantScope()->count();
        (require base_path('database/migrations/2026_10_05_130000_backfill_sandbox_subscriptions.php'))->up();
        $this->assertSame($before, Subscription::withoutTenantScope()->count());
    }
}
