<?php

namespace Tests\Feature;

use App\Jobs\SubmitOrderToPanel;
use App\Models\BotOrder;
use App\Models\Tenant;
use App\Models\TenantPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubmitOrderToPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_forwards_the_order_and_records_the_provider_id(): void
    {
        Http::fake(['*' => Http::response(['order' => '48220'])]);

        $tenant = Tenant::factory()->create();
        $panel = TenantPanel::factory()->for($tenant)->create();
        $order = BotOrder::factory()->for($tenant)->create(['panel_id' => $panel->id]);

        (new SubmitOrderToPanel($order->id))->handle();

        $order->refresh();
        $this->assertSame('48220', $order->provider_order_id);
        $this->assertSame('processing', $order->status);
        $this->assertNull($order->order_error);
    }

    public function test_it_sends_the_api_key_in_the_body_for_param_auth(): void
    {
        Http::fake(['*' => Http::response(['order' => '1'])]);

        $tenant = Tenant::factory()->create();
        $panel = TenantPanel::factory()->for($tenant)->create(['api_key_enc' => 'secret-key']);
        $order = BotOrder::factory()->for($tenant)->create([
            'panel_id' => $panel->id,
            'service_id' => '99',
            'link' => 'https://insta.gr/x',
            'quantity' => 250,
        ]);

        (new SubmitOrderToPanel($order->id))->handle();

        Http::assertSent(function ($request) {
            return $request['key'] === 'secret-key'
                && $request['action'] === 'add'
                && $request['service'] === '99'
                && $request['link'] === 'https://insta.gr/x';
        });
    }

    public function test_it_sends_the_api_key_as_headers_for_header_auth(): void
    {
        Http::fake(['*' => Http::response(['order' => '1'])]);

        $tenant = Tenant::factory()->create();
        $panel = TenantPanel::factory()->for($tenant)->headerAuth()->create(['api_key_enc' => 'secret-key']);
        $order = BotOrder::factory()->for($tenant)->create(['panel_id' => $panel->id]);

        (new SubmitOrderToPanel($order->id))->handle();

        Http::assertSent(function ($request) {
            // Both headers go out because panels disagree on which they read.
            return $request->hasHeader('X-Api-Key', 'secret-key')
                && $request->hasHeader('Authorization', 'Bearer secret-key')
                && ! isset($request['key']);
        });
    }

    public function test_a_panel_error_is_recorded_and_the_job_retries(): void
    {
        // The API reports failure in the body with a 200 status.
        Http::fake(['*' => Http::response(['error' => 'Not enough funds'])]);

        $tenant = Tenant::factory()->create();
        $panel = TenantPanel::factory()->for($tenant)->create();
        $order = BotOrder::factory()->for($tenant)->create(['panel_id' => $panel->id]);

        try {
            (new SubmitOrderToPanel($order->id))->handle();
            $this->fail('expected the job to throw so the queue retries it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Not enough funds', $e->getMessage());
        }

        $this->assertSame('Not enough funds', $order->fresh()->order_error);
        $this->assertNull($order->fresh()->provider_order_id);
    }

    public function test_a_response_with_no_order_id_is_an_error(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);

        $tenant = Tenant::factory()->create();
        $panel = TenantPanel::factory()->for($tenant)->create();
        $order = BotOrder::factory()->for($tenant)->create(['panel_id' => $panel->id]);

        try {
            (new SubmitOrderToPanel($order->id))->handle();
            $this->fail('expected a throw');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertStringContainsString('no order ID', (string) $order->fresh()->order_error);
    }

    public function test_an_already_submitted_order_is_not_sent_twice(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        $panel = TenantPanel::factory()->for($tenant)->create();
        $order = BotOrder::factory()->for($tenant)->submitted()->create(['panel_id' => $panel->id]);

        (new SubmitOrderToPanel($order->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('48220', $order->fresh()->provider_order_id);
    }

    public function test_an_order_with_no_panel_is_skipped(): void
    {
        Http::fake();

        $order = BotOrder::factory()->create(['panel_id' => null]);

        (new SubmitOrderToPanel($order->id))->handle();

        Http::assertNothingSent();
    }

    public function test_a_missing_order_is_ignored(): void
    {
        Http::fake();

        (new SubmitOrderToPanel(999_999))->handle();

        Http::assertNothingSent();
    }

    public function test_it_runs_without_a_tenant_session(): void
    {
        // Queue workers have no authenticated tenant, so the global scope
        // cannot be relied on to find the order.
        Http::fake(['*' => Http::response(['order' => '777'])]);

        $tenant = Tenant::factory()->create();
        $panel = TenantPanel::factory()->for($tenant)->create();
        $order = BotOrder::factory()->for($tenant)->create(['panel_id' => $panel->id]);

        $this->assertGuest('tenant');

        (new SubmitOrderToPanel($order->id))->handle();

        $this->assertSame('777', $order->fresh()->provider_order_id);
    }

    public function test_it_does_not_reach_another_tenants_panel(): void
    {
        Http::fake();

        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        $bobsPanel = TenantPanel::factory()->for($bob)->create();
        // An order of Alice's pointing at Bob's panel should never transact.
        $order = BotOrder::factory()->for($alice)->create(['panel_id' => null]);
        $order->forceFill(['panel_id' => $bobsPanel->id])->saveQuietly();

        (new SubmitOrderToPanel($order->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('Panel no longer connected', $order->fresh()->order_error);
    }
}
