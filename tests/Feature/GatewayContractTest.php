<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Billing\PlatformGateways;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StatusCheckable;
use App\Services\Payments\WebhookVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contract every payment client has to keep.
 *
 * These run over config/gateways.php rather than naming clients one by one, so
 * adding a gateway is covered the moment it is registered — which is the point
 * of the interface. A client that half-implements it fails here rather than in
 * production, where the symptom is a customer's payment silently never
 * confirming.
 */
class GatewayContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function clientFor(string $code): ?PaymentGateway
    {
        $row = TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => $code,
            'api_key_enc' => 'api-key',
            'webhook_secret_enc' => 'secret',
            'extra_enc' => 'extra',
            'status' => 'active',
        ]);

        return app(GatewayFactory::class)->make($row);
    }

    /** @return array<int, string> */
    private function readyCodes(): array
    {
        return array_values(array_filter(
            array_keys(Gateway::all()),
            fn (string $code) => Gateway::isReady($code),
        ));
    }

    /**
     * `ready` in config is what the top-up flow and the dashboard both trust.
     * A gateway marked ready with no client behind it looks connected to a
     * reseller and fails for their customer.
     */
    public function test_every_ready_gateway_has_a_client(): void
    {
        foreach ($this->readyCodes() as $code) {
            $this->assertInstanceOf(
                PaymentGateway::class,
                $this->clientFor($code),
                "{$code} is marked ready but the factory builds no client for it",
            );
        }
    }

    /**
     * The other direction: a client that exists but is not marked ready is
     * work that was finished and never switched on.
     */
    public function test_no_gateway_has_a_client_it_is_not_using(): void
    {
        $unwiredWithClient = [];

        foreach (array_keys(Gateway::all()) as $code) {
            if (! Gateway::isReady($code) && $this->clientFor($code) !== null) {
                $unwiredWithClient[] = $code;
            }
        }

        $this->assertSame(
            [],
            $unwiredWithClient,
            'These have a client but are still marked not ready: '
                .implode(', ', $unwiredWithClient),
        );
    }

    /**
     * Every gateway confirms payment one way or the other: a signed webhook,
     * or a status call for the ones whose notification proves nothing. A
     * gateway with neither can never credit a wallet.
     */
    public function test_every_ready_gateway_can_confirm_a_payment(): void
    {
        foreach ($this->readyCodes() as $code) {
            $client = $this->clientFor($code);

            $this->assertTrue(
                $client instanceof WebhookVerifier || $client instanceof StatusCheckable,
                "{$code} can neither verify a webhook nor check a status, so nothing it takes can ever be credited",
            );
        }
    }

    /**
     * Gateways flagged confirm_by_api are routed past signature verification
     * entirely — PaymentWebhookController asks them instead. One that cannot be
     * asked would silently confirm nothing.
     */
    public function test_gateways_confirmed_by_api_can_be_asked(): void
    {
        foreach ($this->readyCodes() as $code) {
            if (! Gateway::confirmsByApi($code)) {
                continue;
            }

            $this->assertInstanceOf(
                StatusCheckable::class,
                $this->clientFor($code),
                "{$code} is confirmed by API but cannot be asked for a status",
            );
        }
    }

    /**
     * A credential the form never collects is one the client will read as an
     * empty string, which usually surfaces as an authentication failure at the
     * worst moment.
     */
    public function test_every_ready_gateway_declares_its_credential_fields(): void
    {
        foreach ($this->readyCodes() as $code) {
            $fields = Gateway::all()[$code]['fields'] ?? [];

            $this->assertNotEmpty($fields, "{$code} declares no credential fields");

            foreach ($fields as $field) {
                $this->assertContains(
                    $field['store'],
                    ['api_key', 'webhook_secret', 'extra'],
                    "{$code} stores a field in a column that does not exist",
                );
            }
        }
    }

    /** Every gateway needs a label; the dashboard and the bot menu both show it. */
    public function test_every_gateway_has_a_label(): void
    {
        foreach (array_keys(Gateway::all()) as $code) {
            $this->assertNotSame(
                '',
                trim(Gateway::label($code)),
                "{$code} has no label"
            );
        }
    }

    /**
     * The platform's own clients go through the same contract — BillingController
     * calls initiate() on whatever this returns.
     */
    public function test_platform_billing_clients_honour_the_same_contract(): void
    {
        foreach (array_keys((array) config('billing.gateways', [])) as $code) {
            $client = PlatformGateways::make($code);

            if ($client === null) {
                continue;
            }

            $this->assertInstanceOf(PaymentGateway::class, $client, "{$code} is not a PaymentGateway");
        }
    }
}
