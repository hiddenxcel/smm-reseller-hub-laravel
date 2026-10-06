<?php

namespace Tests\Feature;

use App\Actions\Payments\CompleteTopup;
use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\AnypayClient;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\PaymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * AnyPay: a signed link to its hosted page, and a signed notification back.
 *
 * What this holds: the link carries a signature AnyPay will accept, a
 * notification is believed only when it is signed with the project's secret and
 * says fully paid on a real (not test) payment, and AnyPay is answered "OK".
 */
class AnypayTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '1399';

    private const SECRET = 'NLmx0woAqrgHYnMbDSVLChCJ77R8adf';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['currency.usd_to' => ['USD' => 1.0, 'TZS' => 2600.0, 'KES' => 130.0, 'EUR' => 0.9]]);

        $this->tenant = Tenant::factory()->create();
    }

    private function client(): AnypayClient
    {
        return new AnypayClient(self::PROJECT, self::SECRET);
    }

    private function request(string $amount = '5000', string $currency = 'TZS'): PaymentRequest
    {
        return new PaymentRequest(
            reference: 'tu_01ABC',
            amount: $amount,
            currency: $currency,
            webhookUrl: 'https://hub.test/webhooks/payment/anypay',
        );
    }

    /** @return array<string, string> */
    private function link(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function notification(string $payId, array $override = []): array
    {
        $params = [
            'merchant_id' => self::PROJECT,
            'transaction_id' => '4950030',
            'pay_id' => $payId,
            'amount' => '1.92',
            'currency' => 'USD',
            'profit' => '150.00',
            'status' => 'paid',
            'test' => '0',
            ...$override,
        ];

        $params['sign'] ??= hash('sha256', implode(':', [
            $params['currency'], $params['amount'], $params['pay_id'],
            $params['merchant_id'], $params['status'], self::SECRET,
        ]));

        return $params;
    }

    // ---- the payment link ------------------------------------------------

    public function test_the_link_goes_to_the_hosted_form_with_a_valid_signature(): void
    {
        $initiation = $this->client()->initiate($this->request(), 'Wallet top-up');

        $this->assertTrue($initiation->started);
        $this->assertStringStartsWith('https://anypay.io/merchant?', $initiation->redirectUrl);

        $q = $this->link($initiation->redirectUrl);

        // The formula from AnyPay's docs, spelled out independently.
        $expected = hash('sha256', implode(':', [
            self::PROJECT, $q['pay_id'], $q['amount'], $q['currency'], $q['desc'], '', '', self::SECRET,
        ]));

        $this->assertSame($expected, $q['sign']);
        $this->assertSame(self::PROJECT, $q['merchant_id']);
        $this->assertSame($initiation->reference, $q['pay_id']);
    }

    public function test_the_order_number_is_at_most_fifteen_digits(): void
    {
        $payId = $this->client()->initiate($this->request())->reference;

        $this->assertMatchesRegularExpression('/^\d{1,15}$/', $payId);
    }

    public function test_a_currency_anypay_does_not_take_is_charged_in_dollars(): void
    {
        $q = $this->link($this->client()->initiate($this->request('5200', 'TZS'))->redirectUrl);

        $this->assertSame('USD', $q['currency']);
        $this->assertSame('2.00', $q['amount']);
    }

    public function test_a_currency_anypay_takes_is_kept(): void
    {
        $q = $this->link($this->client()->initiate($this->request('7.50', 'EUR'))->redirectUrl);

        $this->assertSame('EUR', $q['currency']);
        $this->assertSame('7.50', $q['amount']);
    }

    public function test_no_rate_means_no_payment_rather_than_a_wrong_amount(): void
    {
        $initiation = $this->client()->initiate($this->request('100', 'XYZ'));

        $this->assertFalse($initiation->started);
        $this->assertStringContainsString('exchange rate', $initiation->message);
    }

    public function test_it_will_not_start_without_credentials(): void
    {
        $this->assertFalse((new AnypayClient('', ''))->initiate($this->request())->started);
    }

    // ---- verifying a notification ---------------------------------------

    public function test_a_correctly_signed_notification_verifies(): void
    {
        $this->assertTrue($this->client()->verifyParams($this->notification('123')));
    }

    public function test_a_tampered_amount_or_status_does_not_verify(): void
    {
        $good = $this->notification('123');

        $this->assertFalse($this->client()->verifyParams([...$good, 'amount' => '0.01']));
        $this->assertFalse($this->client()->verifyParams([...$good, 'status' => 'waiting']));
    }

    public function test_a_notification_for_another_project_does_not_verify(): void
    {
        $this->assertFalse($this->client()->verifyParams($this->notification('123', ['merchant_id' => '1']))
        );
    }

    public function test_a_missing_or_wrong_signature_does_not_verify(): void
    {
        $this->assertFalse($this->client()->verifyParams($this->notification('123', ['sign' => 'deadbeef'])));

        $params = $this->notification('123');
        unset($params['sign']);
        $this->assertFalse($this->client()->verifyParams($params));
    }

    public function test_nothing_verifies_without_a_secret(): void
    {
        $this->assertFalse((new AnypayClient(self::PROJECT, ''))->verifyParams($this->notification('123')));
    }

    public function test_only_a_full_real_payment_counts_as_paid(): void
    {
        $this->assertTrue(AnypayClient::isPaid(['status' => 'paid', 'test' => '0']));
        $this->assertFalse(AnypayClient::isPaid(['status' => 'paid', 'test' => '1']));
        $this->assertFalse(AnypayClient::isPaid(['status' => 'partially-paid']));

        foreach (['waiting', 'canceled', 'expired', 'refund', 'error'] as $status) {
            $this->assertFalse(AnypayClient::isPaid(['status' => $status]), $status);
        }
    }

    // ---- the webhook end to end -----------------------------------------

    private function pendingPayment(): BotPayment
    {
        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => 'anypay',
            'api_key_enc' => self::PROJECT,
            'webhook_secret_enc' => self::SECRET,
            'status' => 'active',
        ]);

        $customer = BotCustomer::factory()->for($this->tenant)->create(['balance' => '0.00']);

        $payment = BotPayment::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'wallet_topup',
            'customer_id' => $customer->id,
            'gateway' => 'anypay',
            'transaction_ref' => 'tu_01ABC',
            'gateway_reference' => '170000000000012',
            'amount' => '5.00',
            'status' => 'pending',
        ]);

        return $payment;
    }

    public function test_a_signed_paid_notification_credits_the_wallet_and_is_answered_ok(): void
    {
        $payment = $this->pendingPayment();

        $response = $this->post(route('webhooks.payment', 'anypay'), $this->notification('170000000000012'));

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame('5.00', (string) BotCustomer::find($payment->customer_id)->balance);
    }

    public function test_a_notification_sent_as_a_get_works_too(): void
    {
        $payment = $this->pendingPayment();

        $this->get(route('webhooks.payment', 'anypay').'?'.http_build_query($this->notification('170000000000012')))
            ->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
    }

    public function test_a_forged_notification_credits_nothing(): void
    {
        $payment = $this->pendingPayment();

        $this->post(route('webhooks.payment', 'anypay'), $this->notification('170000000000012', ['sign' => 'forged']))
            ->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_an_unpaid_or_test_notification_credits_nothing_but_is_still_answered_ok(): void
    {
        $payment = $this->pendingPayment();

        foreach ([['status' => 'waiting'], ['test' => '1']] as $override) {
            $this->post(route('webhooks.payment', 'anypay'), $this->notification('170000000000012', $override))
                ->assertOk()
                ->assertSee('OK', false);
        }

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_retried_notification_credits_only_once(): void
    {
        $payment = $this->pendingPayment();
        $params = $this->notification('170000000000012');

        $this->post(route('webhooks.payment', 'anypay'), $params)->assertOk();
        $this->post(route('webhooks.payment', 'anypay'), $params)->assertOk();

        $this->assertSame('5.00', (string) BotCustomer::find($payment->customer_id)->balance);
    }

    // ---- wiring ----------------------------------------------------------

    public function test_the_factory_builds_it_and_it_is_marked_ready(): void
    {
        $this->assertTrue(Gateway::isReady('anypay'));

        $row = new TenantPaymentGateway([
            'gateway' => 'anypay', 'api_key_enc' => self::PROJECT, 'webhook_secret_enc' => self::SECRET,
        ]);

        $this->assertInstanceOf(AnypayClient::class, app(GatewayFactory::class)->make($row));
    }

    public function test_the_gateways_page_tells_the_reseller_which_url_to_paste_into_anypay(): void
    {
        $this->actingAs($this->tenant)->get(route('order-bot.gateways'))->assertInertia(function (AssertableInertia $page) {
            $anypay = collect($page->toArray()['props']['gateways'])->firstWhere('code', 'anypay');

            $this->assertSame(route('webhooks.payment', 'anypay'), $anypay['webhookUrl']);
            $this->assertSame(['api_key', 'webhook_secret'], array_column($anypay['fields'], 'name'));
        });
    }
}
