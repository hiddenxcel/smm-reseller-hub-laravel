<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Binance Pay end-to-end, over the real webhook route.
 *
 * Two things about its payload make it unlike every other gateway here, and
 * both are load-bearing:
 *
 *   1. The part that identifies the payment — merchantTradeNo — is not in the
 *      JSON body proper. It sits inside `data`, which is itself a JSON
 *      *string*, so nothing finds it until that string is decoded.
 *
 *   2. The envelope carries a top-level `status` of "SUCCESS" that means only
 *      "this notification was produced". Whether the money arrived is
 *      bizStatus. Crediting on the former would pay out on a closed order.
 *
 * PaymentWebhookVerificationTest covers the signature itself; this covers what
 * the controller does with a correctly signed one.
 */
class BinancePayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'merchant-api-secret';

    private Tenant $tenant;

    private BotCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->customer = BotCustomer::factory()
            ->for($this->tenant)
            ->withBalance('0.00')
            ->create();

        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => 'binance',
            'api_key_enc' => 'merchant-api-key',
            'webhook_secret_enc' => self::SECRET,
            'status' => 'active',
        ]);
    }

    public function test_a_paid_order_credits_the_wallet(): void
    {
        $payment = $this->pendingPayment('BINREF1');

        $this->send($this->payload('BINREF1', 'PAY_SUCCESS'))
            ->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame('5.00', $this->customer->fresh()->balance);
    }

    /**
     * The trap this gateway sets: `status` says SUCCESS on a payload whose
     * bizStatus says the order was closed unpaid.
     */
    public function test_a_closed_order_credits_nothing_despite_a_success_envelope(): void
    {
        $payment = $this->pendingPayment('BINREF2');

        $this->send($this->payload('BINREF2', 'PAY_CLOSED'))
            ->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('0.00', $this->customer->fresh()->balance);
    }

    public function test_a_forged_signature_is_refused(): void
    {
        $payment = $this->pendingPayment('BINREF3');

        $body = json_encode($this->payload('BINREF3', 'PAY_SUCCESS'));

        $this->call(
            'POST',
            '/webhooks/payment/binance',
            server: $this->serverHeaders([
                'BinancePay-Timestamp' => '1700000000000',
                'BinancePay-Nonce' => 'nonce-1',
                // Signed with the wrong secret — the shape is right, the
                // authority is not.
                'BinancePay-Signature' => strtoupper(hash_hmac(
                    'sha512',
                    "1700000000000\nnonce-1\n{$body}\n",
                    'not-the-secret',
                )),
            ]),
            content: $body,
        )->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    /**
     * Gateways retry for hours. The second delivery of a payment already
     * credited must leave the balance where it is.
     */
    public function test_a_replayed_webhook_credits_only_once(): void
    {
        $this->pendingPayment('BINREF4');

        $payload = $this->payload('BINREF4', 'PAY_SUCCESS');

        $this->send($payload)->assertOk();
        $this->send($payload)->assertOk();

        $this->assertSame('5.00', $this->customer->fresh()->balance);
    }

    private function pendingPayment(string $reference): BotPayment
    {
        return BotPayment::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'wallet_topup',
            'customer_id' => $this->customer->id,
            'gateway' => 'binance',
            'transaction_ref' => $reference,
            'amount' => '5.00',
            'status' => 'pending',
        ]);
    }

    /**
     * A Binance Pay notification as Binance actually sends it: the interesting
     * half encoded as a string inside `data`.
     *
     * @return array<string, string>
     */
    private function payload(string $reference, string $bizStatus): array
    {
        return [
            'bizType' => 'PAY',
            'bizId' => '29383937493038367292',
            // Not the payment's outcome — see the class docblock.
            'status' => 'SUCCESS',
            'data' => json_encode([
                'merchantTradeNo' => $reference,
                'bizStatus' => $bizStatus,
                'productName' => 'Wallet top-up',
                'totalFee' => 5.0,
                'currency' => 'USDT',
            ]),
        ];
    }

    /** @param  array<string, string>  $payload */
    private function send(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = '1700000000000';
        $nonce = 'nonce-1';

        return $this->call(
            'POST',
            '/webhooks/payment/binance',
            server: $this->serverHeaders([
                'BinancePay-Timestamp' => $timestamp,
                'BinancePay-Nonce' => $nonce,
                'BinancePay-Signature' => strtoupper(hash_hmac(
                    'sha512',
                    "{$timestamp}\n{$nonce}\n{$body}\n",
                    self::SECRET,
                )),
            ]),
            content: $body,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }
}
