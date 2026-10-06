<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\PayuClient;
use App\Services\Payments\PaymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * PayU (India): a signed link to a page of ours that posts the order to PayU,
 * and a response that is believed only when its hash checks out.
 */
class PayuTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'gtKFFx';

    private const SALT = 'eCwWELxi';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['currency.usd_to' => ['USD' => 1.0, 'TZS' => 2600.0, 'INR' => 88.0]]);

        $this->tenant = Tenant::factory()->create();
    }

    private function client(string $mode = ''): PayuClient
    {
        return new PayuClient(self::KEY, self::SALT, $mode);
    }

    private function request(string $amount = '2', string $currency = 'USD'): PaymentRequest
    {
        return new PaymentRequest(
            reference: 'tu_01ABCDEFGHIJKLMNOPQRSTUVWX',
            amount: $amount,
            currency: $currency,
            webhookUrl: 'https://hub.test/webhooks/payment/payu',
            phone: '+91 98765 43210',
            customerName: 'Asha Mwangi',
        );
    }

    /** @return array{action: string, fields: array<string, string>} */
    private function orderBehind(string $link): array
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return json_decode(Crypt::decryptString($query['d']), true);
    }

    /** PayU's response hash, written out independently of the client. */
    private function responseHash(array $p, string $prefix = ''): string
    {
        return hash('sha512', $prefix.self::SALT.'|'.$p['status'].'||||||||||'.'|'
            .implode('|', [$p['email'], $p['firstname'], $p['productinfo'], $p['amount'], $p['txnid'], $p['key']]));
    }

    private function response(string $txnid, array $override = []): array
    {
        $p = [
            'key' => self::KEY,
            'txnid' => $txnid,
            'amount' => '176.00',
            'productinfo' => 'Wallet top-up',
            'firstname' => 'Asha',
            'email' => 'asha@example.com',
            'status' => 'success',
            'mihpayid' => '403993715521',
            ...$override,
        ];
        $p['hash'] ??= $this->responseHash($p);

        return $p;
    }

    // ---- the link and the form page -------------------------------------

    public function test_the_link_is_a_signed_page_of_ours_not_a_bare_payu_url(): void
    {
        $initiation = $this->client()->initiate($this->request());

        $this->assertTrue($initiation->started);
        $this->assertStringStartsWith(url('/pay/form'), $initiation->redirectUrl);
        $this->assertStringContainsString('signature=', $initiation->redirectUrl);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,25}$/', $initiation->reference);
    }

    public function test_the_order_carries_the_documented_hash_and_never_the_salt(): void
    {
        $initiation = $this->client()->initiate($this->request());
        $order = $this->orderBehind($initiation->redirectUrl);
        $f = $order['fields'];

        // sha512(key|txnid|amount|productinfo|firstname|email|udf1..udf5||||||salt), spelled out.
        $expected = hash('sha512', implode('|', [
            self::KEY, $f['txnid'], $f['amount'], $f['productinfo'], $f['firstname'], $f['email'],
            '', '', '', '', '',
        ]).'||||||'.self::SALT);

        $this->assertSame($expected, $f['hash']);
        $this->assertSame('https://secure.payu.in/_payment', $order['action']);
        $this->assertSame($initiation->reference, $f['txnid']);
        $this->assertSame('Asha', $f['firstname']);
        $this->assertSame('9876543210', $f['phone']);
        $this->assertStringNotContainsString(self::SALT, json_encode($order));
    }

    public function test_the_amount_is_charged_in_rupees(): void
    {
        $fields = $this->orderBehind($this->client()->initiate($this->request('2', 'USD'))->redirectUrl)['fields'];

        $this->assertSame('176.00', $fields['amount']);
    }

    public function test_test_mode_posts_to_the_sandbox(): void
    {
        $order = $this->orderBehind($this->client('test')->initiate($this->request())->redirectUrl);

        $this->assertSame('https://test.payu.in/_payment', $order['action']);
    }

    public function test_no_rate_means_no_payment_rather_than_a_wrong_amount(): void
    {
        $initiation = $this->client()->initiate($this->request('5', 'XYZ'));

        $this->assertFalse($initiation->started);
        $this->assertStringContainsString('exchange rate', $initiation->message);
    }

    public function test_it_will_not_start_without_credentials(): void
    {
        $this->assertFalse((new PayuClient('', ''))->initiate($this->request())->started);
    }

    public function test_the_link_opens_a_page_that_posts_to_payu(): void
    {
        $link = $this->client()->initiate($this->request())->redirectUrl;

        $this->get($link)
            ->assertOk()
            ->assertSee('action="https://secure.payu.in/_payment"', false)
            ->assertSee('name="hash"', false)
            ->assertDontSee(self::SALT);
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $link = $this->client()->initiate($this->request())->redirectUrl;

        $this->get($link.'x')->assertForbidden();
    }

    public function test_the_page_will_not_post_to_an_address_off_the_allow_list(): void
    {
        $payload = Crypt::encryptString(json_encode([
            'action' => 'https://evil.example/steal',
            'fields' => ['key' => 'x'],
        ]));

        $this->get(URL::temporarySignedRoute('payment.form', now()->addHour(), ['d' => $payload]))
            ->assertNotFound();
    }

    // ---- verifying PayU's response --------------------------------------

    public function test_a_correctly_hashed_response_verifies(): void
    {
        $this->assertTrue($this->client()->verifyParams($this->response('tx1')));
    }

    public function test_additional_charges_are_part_of_the_hash(): void
    {
        $p = $this->response('tx1', ['additionalCharges' => '2.00', 'hash' => 'x']);
        $p['hash'] = $this->responseHash($p, '2.00|');

        $this->assertTrue($this->client()->verifyParams($p));
    }

    public function test_a_tampered_response_does_not_verify(): void
    {
        $good = $this->response('tx1');

        $this->assertFalse($this->client()->verifyParams([...$good, 'amount' => '1.00']));
        $this->assertFalse($this->client()->verifyParams([...$good, 'status' => 'failure']));
        $this->assertFalse($this->client()->verifyParams([...$good, 'key' => 'other']));
        $this->assertFalse($this->client()->verifyParams([...$good, 'hash' => 'deadbeef']));
    }

    public function test_nothing_verifies_without_a_salt(): void
    {
        $this->assertFalse((new PayuClient(self::KEY, ''))->verifyParams($this->response('tx1')));
    }

    public function test_only_success_counts_as_paid(): void
    {
        $this->assertTrue(PayuClient::isPaid(['status' => 'success']));

        foreach (['failure', 'pending', 'userCancelled', ''] as $status) {
            $this->assertFalse(PayuClient::isPaid(['status' => $status]), $status);
        }
    }

    // ---- asking PayU ----------------------------------------------------

    public function test_it_asks_payu_for_the_status_with_the_documented_hash(): void
    {
        Http::fake(['info.payu.in/*' => Http::response([
            'status' => 1,
            'transaction_details' => ['tx1' => ['status' => 'success']],
        ])]);

        $this->assertSame('completed', $this->client()->checkStatus('tx1'));

        Http::assertSent(fn ($request) => $request['command'] === 'verify_payment'
            && $request['var1'] === 'tx1'
            && $request['hash'] === hash('sha512', self::KEY.'|verify_payment|tx1|'.self::SALT));
    }

    public function test_a_failed_or_unknown_transaction_is_not_completed(): void
    {
        Http::fake(['info.payu.in/*' => Http::sequence()
            ->push(['transaction_details' => ['tx1' => ['status' => 'failure']]])
            ->push(['transaction_details' => ['tx1' => ['status' => 'pending']]])]);

        $this->assertSame('failed', $this->client()->checkStatus('tx1'));
        $this->assertSame('pending', $this->client()->checkStatus('tx1'));
    }

    // ---- the customer coming back ---------------------------------------

    private function pendingPayment(string $txnid): BotPayment
    {
        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => 'payu',
            'api_key_enc' => self::KEY,
            'webhook_secret_enc' => self::SALT,
            'status' => 'active',
        ]);

        $customer = BotCustomer::factory()->for($this->tenant)->create(['balance' => '0.00']);

        return BotPayment::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'wallet_topup',
            'customer_id' => $customer->id,
            'gateway' => 'payu',
            'transaction_ref' => 'tu_01ABCDEFGHIJKLMNOPQRSTUVWX',
            'gateway_reference' => $txnid,
            'amount' => '2.00',
            'status' => 'pending',
        ]);
    }

    public function test_a_verified_success_credits_the_wallet_and_sends_the_customer_on(): void
    {
        $payment = $this->pendingPayment('tuabc123');

        $this->post(route('webhooks.payment.return', 'payu'), $this->response('tuabc123'))
            ->assertRedirect(route('payment.thanks'));

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame('2.00', (string) BotCustomer::find($payment->customer_id)->balance);
    }

    public function test_a_forged_return_credits_nothing_but_still_sends_the_customer_on(): void
    {
        $payment = $this->pendingPayment('tuabc123');

        $this->post(route('webhooks.payment.return', 'payu'), $this->response('tuabc123', ['hash' => 'forged']))
            ->assertRedirect(route('payment.thanks'));

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_verified_failure_credits_nothing(): void
    {
        $payment = $this->pendingPayment('tuabc123');

        $p = $this->response('tuabc123', ['status' => 'failure', 'hash' => 'x']);
        $p['hash'] = $this->responseHash($p);

        $this->post(route('webhooks.payment.return', 'payu'), $p);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_second_delivery_credits_only_once(): void
    {
        $payment = $this->pendingPayment('tuabc123');
        $p = $this->response('tuabc123');

        $this->post(route('webhooks.payment.return', 'payu'), $p);
        $this->post(route('webhooks.payment', 'payu'), $p)->assertOk();

        $this->assertSame('2.00', (string) BotCustomer::find($payment->customer_id)->balance);
    }

    public function test_visiting_the_return_address_with_nothing_credits_nothing(): void
    {
        $this->get(route('webhooks.payment.return', 'payu'))->assertRedirect(route('payment.thanks'));
    }

    // ---- wiring ----------------------------------------------------------

    public function test_the_factory_builds_it_and_it_is_ready(): void
    {
        $this->assertTrue(Gateway::isReady('payu'));

        $row = new TenantPaymentGateway(['gateway' => 'payu', 'api_key_enc' => 'k', 'webhook_secret_enc' => 's']);

        $this->assertInstanceOf(PayuClient::class, app(GatewayFactory::class)->make($row));
    }

    public function test_the_other_indian_providers_can_hold_keys_but_cannot_take_payments_yet(): void
    {
        foreach (['ebanx', 'adyen', 'ppro', 'nomupay'] as $code) {
            $this->assertTrue(Gateway::exists($code), $code);
            $this->assertFalse(Gateway::isReady($code), $code);
        }
    }
}
