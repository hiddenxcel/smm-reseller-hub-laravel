<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Landing'));
    }

    public function test_it_advertises_the_real_prices(): void
    {
        // The page reads from the plans table so it can never quote a price
        // that checkout does not charge.
        Plan::factory()->create([
            'code' => 'order_bot',
            'service_key' => ServiceKey::OrderBot,
            'name' => 'Order Bot',
            'price_monthly' => '17.00',
            'price_yearly' => '163.20',
        ]);

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('plans.order_bot', fn (AssertableInertia $plan) => $plan
                ->where('name', 'Order Bot')
                // Loose comparison: JSON does not distinguish 17 from 17.0.
                ->where('monthly', fn (float|int $value) => (float) $value === 17.0)
                ->where('yearly', fn (float|int $value) => (float) $value === 163.2)
                ->etc()
            )
        );
    }

    public function test_inactive_plans_are_not_advertised(): void
    {
        Plan::factory()->create([
            'code' => 'retired',
            'service_key' => ServiceKey::AiChat,
            'status' => 'inactive',
        ]);

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans', [])
        );
    }

    public function test_it_is_reachable_without_logging_in(): void
    {
        $this->assertGuest('tenant');

        $this->get('/')->assertOk();
    }
}
