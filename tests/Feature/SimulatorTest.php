<?php

namespace Tests\Feature;

use App\Jobs\SubmitOrderToPanel;
use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use App\Services\Bots\BotSettings;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The practice chat: the real bot, driven from the browser, leaving no trace.
 *
 * What matters is both halves of that. It must answer like the real thing —
 * a menu, an order, a wallet that goes down — and it must not leave a single
 * row behind, because the person trying it may be a reseller with a live shop
 * whose orders and revenue this would otherwise pollute.
 */
class SimulatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::factory()->create(['business_name' => 'Kuza Panel']);
    }

    private function say(string $text, string $bot = 'order', bool $reset = false)
    {
        return $this->actingAs($this->tenant, 'tenant')
            ->postJson(route('simulator.send'), ['bot' => $bot, 'text' => $text, 'reset' => $reset]);
    }

    private function bodies(array $events): string
    {
        return collect($events)->pluck('body')->implode("\n");
    }

    public function test_it_needs_a_login(): void
    {
        $this->postJson(route('simulator.send'), ['bot' => 'order', 'text' => 'hi'])
            ->assertUnauthorized();
    }

    public function test_it_rejects_an_unknown_bot(): void
    {
        $this->say('hi', 'nonsense')->assertUnprocessable();
    }

    public function test_it_greets_with_the_real_menu_on_a_sample_shop(): void
    {
        $response = $this->say('hi', reset: true)->assertOk();

        $response->assertJsonPath('sample', true);
        $response->assertJsonPath('events.0.type', 'list');
        $this->assertStringContainsString('Kuza Panel', $this->bodies($response->json('events')));

        $ids = collect($response->json('events.0.rows'))->pluck('id');
        $this->assertTrue($ids->contains('main:new_order'));
    }

    public function test_a_whole_order_goes_through_and_the_wallet_pays(): void
    {
        $this->say('hi', reset: true);
        $this->say('main:new_order');
        $category = $this->say('plat_Instagram')->json('events.0.rows.0.id');
        $service = $this->say($category)->json('events.0.rows.0.id');
        $this->say($service);                       // quantity list
        $this->say('qty_100');                      // link prompt
        $this->say('https://instagram.com/kuza');   // confirm buttons
        $placed = $this->say('confirm_yes');

        $text = $this->bodies($placed->json('events'));
        $this->assertStringContainsString('placed', mb_strtolower($text));

        // The wallet went down by what the order cost, and it is remembered
        // for the next message even though nothing was saved.
        $this->assertLessThan(10.0, (float) $placed->json('balance'));
        $this->assertSame(0, BotOrder::withoutTenantScope()->count());

        $this->say('hi');
        $tracked = $this->say('main:track');
        $this->assertStringContainsString('Instagram', $this->bodies($tracked->json('events')));
    }

    public function test_nothing_it_does_is_saved(): void
    {
        $this->say('hi', reset: true);
        $this->say('main:profile');
        $this->say('main:referral');

        $this->assertSame(0, BotService::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, BotCustomer::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, BotOrder::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, BotConversation::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_it_keeps_its_place_between_messages(): void
    {
        $this->say('hi', reset: true);
        $first = $this->say('main:new_order');
        $this->assertSame('list', $first->json('events.0.type'));
        $this->assertStringContainsString('platform', mb_strtolower($this->bodies($first->json('events'))));

        // Not "hi" again: the next message is read as a choice from that list.
        $second = $this->say('plat_Instagram');
        $this->assertNotSame(
            $this->bodies($first->json('events')),
            $this->bodies($second->json('events')),
        );
    }

    public function test_it_shows_the_resellers_own_services_once_they_have_some(): void
    {
        BotService::factory()->for($this->tenant)->create([
            'platform' => 'Facebook',
            'status' => 'active',
        ]);

        $response = $this->say('hi', reset: true)->assertOk();
        $response->assertJsonPath('sample', false);

        $platforms = $this->say('main:new_order')->json('events.0.rows');
        $this->assertSame(['Facebook'], collect($platforms)->pluck('title')->all());
    }

    public function test_it_never_sends_an_order_to_a_panel(): void
    {
        BotService::factory()->for($this->tenant)->create(['status' => 'active']);

        $this->say('hi', reset: true);
        $this->assertSame(0, BotOrder::withoutTenantScope()->count());

        Queue::assertNotPushed(SubmitOrderToPanel::class);
    }

    public function test_the_support_bot_answers_without_a_panel(): void
    {
        $response = $this->say('hi', 'support', true)->assertOk();
        $this->assertStringContainsString('Quick Menu', $this->bodies($response->json('events')));

        $this->say('6', 'support');
        $status = $this->say('48220', 'support');

        $this->assertStringContainsString('Order *#48220*', $this->bodies($status->json('events')));
        $this->assertStringContainsString('Rehearsal', $this->bodies($status->json('events')));
    }

    public function test_the_support_bot_does_not_leave_a_ticket_behind(): void
    {
        $this->say('hi', 'support', true);
        $this->say('5', 'support');

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_opening_the_chat_does_not_count_as_trying_it(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->postJson(route('simulator.send'), ['bot' => 'order', 'text' => 'hi', 'reset' => true, 'opening' => true])
            ->assertOk();

        $this->assertFalse((bool) Arr::get(BotSettings::for($this->tenant->id, 'order'), 'shop.bot_tried', false));
        $this->assertFalse(OnboardingProgress::for($this->tenant->fresh())->isComplete(OnboardingStep::TryBot));
    }

    public function test_a_real_message_counts_as_trying_it(): void
    {
        $this->say('main:profile', reset: true);

        $this->assertTrue(OnboardingProgress::for($this->tenant->fresh())->isComplete(OnboardingStep::TryBot));
    }

    public function test_only_the_resellers_own_services_unlock_going_live(): void
    {
        $this->say('main:profile', reset: true);

        $settings = BotSettings::for($this->tenant->id, 'order');
        $this->assertFalse((bool) Arr::get($settings, 'shop.sim_tested', false));

        BotService::factory()->for($this->tenant)->create(['status' => 'active']);
        $this->say('main:profile');

        $settings = BotSettings::for($this->tenant->id, 'order');
        $this->assertTrue((bool) Arr::get($settings, 'shop.sim_tested', false));
    }

    public function test_the_wizard_opens_on_the_practice_chat(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'try'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/TryBot')
                ->where('simulator.business', 'Kuza Panel')
                ->where('simulator.endpoint', route('simulator.send')));
    }

    public function test_someone_already_set_up_is_not_sent_back_to_the_demo(): void
    {
        \App\Models\TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);

        $this->assertTrue(OnboardingProgress::for($this->tenant->fresh())->isComplete(OnboardingStep::TryBot));
    }

    public function test_going_live_accepts_a_test_order_from_the_practice_chat(): void
    {
        $settings = BotSettings::for($this->tenant->id, 'order');
        Arr::set($settings, 'shop.sim_tested', true);
        BotSettings::save($this->tenant->id, 'order', $settings);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.test.golive'))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) Arr::get(BotSettings::for($this->tenant->id, 'order'), 'shop.bot_tested', false));
    }
}
