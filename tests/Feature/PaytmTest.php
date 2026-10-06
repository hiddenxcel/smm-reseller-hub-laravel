<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\PaymentRequest;
use App\Services\Payments\PaytmChecksum;
use App\Services\Payments\PaytmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Paytm (India): a signed initiate call, a link to a page of ours that posts
 * the token to Paytm, and a callback believed only when its checksum verifies.
 */
class PaytmTest extends TestCase
{
    use RefreshDatabase;

    private const MID = 'MERCHANT12345678';

    // AES-128: sixteen characters.
    private const KEY = 'abcdef0123456789';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['currency.usd_to' => ['USD' => 1.0, 'INR' => 88.0]]);

        $this->tenant = Tenant::factory()->create();
    }

    private function client(string $mode = ''): PaytmClient
    {
        return new PaytmClient(self::MID, self::KEY, $mode);
    }

    private function request(string $amount = '2'): PaymentRequest
    {
        return new PaymentRequest(
            reference: 'tu_01ABCDEFGHIJKLMNOPQRSTUVWX',
            amount: $amount,
            currency: 'USD',
            webhookUrl: 'https://hub.test/webhooks/payment/paytm',
            phone: '+91 98765 43210',
        );
    }

    /** Paytm's scheme, written out independently of the class under test. */
    private function decryptChecksum(string $checksum): string
    {
        return (string) openssl_decrypt($checksum, 'AES-128-CBC', self::KEY, 0, '@@@@&&&&####$$$$');
    }

    private function fakeInitiate(): void
    {
        Http::fake([
            '*initiateTransaction*' => Http::response([
                'head' => ['signature' => 'x'],
                'body' => [
                    'resultInfo' => ['resultStatus' => 'S', 'resultCode' => '0000', 'resultMsg' => 'Success'],
                    'txnToken' => 'tok-123',
                ],
            ]),
        ]);
    }

    // ---- the checksum ----------------------------------------------------

    public function test_a_checksum_is_the_encrypted_hash_and_salt_of_the_data(): void
    {
        $data = '{"orderId":"abc","mid":"M"}';

        $decrypted = $this->decryptChecksum(PaytmChecksum::generate($data, self::KEY));
        $salt = substr($decrypted, -4);

        $this->assertSame(hash('sha256', $data.'|'.$salt).$salt, $decrypted);
    }

    public function test_a_checksum_verifies_only_for_the_same_data_and_key(): void
    {
        $checksum = PaytmChecksum::generate('payload', self::KEY);

        $this->assertTrue(PaytmChecksum::verify('payload', self::KEY, $checksum));
        $this->assertFalse(PaytmChecksum::verify('payload!', self::KEY, $checksum));
        $this->assertFalse(PaytmChecksum::verify('payload', 'ffffffffffffffff', $checksum));
        $this->assertFalse(PaytmChecksum::verify('payload', self::KEY, 'not-a-checksum'));
        $this->assertFalse(PaytmChecksum::verify('payload', self::KEY, ''));
    }

    public function test_fields_are_signed_by_value_sorted_by_name(): void
    {
        $fields = ['STATUS' => 'TXN_SUCCESS', 'ORDERID' => 'o1', 'TXNAMOUNT' => '10.00', 'EMPTY' => null];

        $decrypted = $this->decryptChecksum(PaytmChecksum::generate($fields, self::KEY));
        $salt = substr($decrypted, -4);

        // EMPTY, ORDERID, STATUS, TXNAMOUNT — null counts as an empty value.
        $this->assertSame(hash('sha256', '|o1|TXN_SUCCESS|10.00|'.$salt).$salt, $decrypted);
    }

    // ---- starting a payment ----------------------------------------------

    public function test_initiating_sends_a_signed_body_to_paytm(): void
    {
        $this->fakeInitiate();

        $this->client()->initiate($this->request(), 'Wallet top-up');

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $body = json_encode($payload['body'], JSON_UNESCAPED_SLASHES);

            return str_starts_with($request->url(), 'https://secure.paytmpayments.com/theia/api/v1/initiateTransaction?mid='.self::MID.'&orderId=')
                && $payload['body']['mid'] === self::MID
                && $payload['body']['requestType'] === 'Payment'
                && $payload['body']['txnAmount'] === ['value' => '176.00', 'currency' => 'INR']
                && PaytmChecksum::verify($body, self::KEY, $payload['head']['signature']);
        });
    }

    public function test_the_link_opens_a_page_that_posts_the_token_to_paytm(): void
    {
        $this->fakeInitiate();

        $initiation = $this->client()->initiate($this->request());

        $this->assertTrue($initiation->started);
        $this->assertStringStartsWith(url('/pay/form'), $initiation->redirectUrl);

        $this->get($initiation->redirectUrl)
            ->assertOk()
            ->assertSee('https://secure.paytmpayments.com/theia/api/v1/showPaymentPage?mid='.self::MID.'&amp;orderId='.$initiation->reference, false)
            ->assertSee('name="txnToken" value="tok-123"', false)
            ->assertDontSee(self::KEY);
    }

    public function test_test_mode_uses_the_sandbox_and_its_website_name(): void
    {
        $this->fakeInitiate();

        $this->client('test')->initiate($this->request());

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://securestage.paytmpayments.com/')
            && json_decode($request->body(), true)['body']['websiteName'] === 'WEBSTAGING');
    }

    public function test_a_refusal_from_paytm_fails_the_payment_with_its_message(): void
    {
        Http::fake(['*' => Http::response(['body' => ['resultInfo' => ['resultStatus' => 'F', 'resultMsg' => 'Invalid MID']]])]);

        $initiation = $this->client()->initiate($this->request());

        $this->assertFalse($initiation->started);
        $this->assertSame('Invalid MID', $initiation->message);
    }

    public function test_no_rate_and_no_credentials_start_nothing(): void
    {
        Http::fake();

        $noRate = new PaymentRequest('r', '5', 'XYZ', 'https://x.test', '');
        $this->assertStringContainsString('exchange rate', $this->client()->initiate($noRate)->message);
        $this->assertFalse((new PaytmClient('', ''))->initiate($this->request())->started);

        Http::assertNothingSent();
    }

    public function test_a_payment_page_off_the_allow_list_is_refused(): void
    {
        $payload = Crypt::encryptString(json_encode([
            'action' => 'https://evil.example/theia/api/v1/showPaymentPage?x=1',
            'fields' => ['mid' => 'x'],
        ]));

        $this->get(URL::temporarySignedRoute('payment.form', now()->addHour(), ['d' => $payload]))->assertNotFound();
    }

    // ---- the callback ----------------------------------------------------

    private function paytmCallback(string $orderId, array $override = []): array
    {
        $fields = [
            'MID' => self::MID,
            'ORDERID' => $orderId,
            'TXNID' => '20261006111212800110168000000000001',
            'TXNAMOUNT' => '176.00',
            'CURRENCY' => 'INR',
            'STATUS' => 'TXN_SUCCESS',
            'RESPCODE' => '01',
            'RESPMSG' => 'Txn Success',
            'PAYMENTMODE' => 'UPI',
            ...$override,
        ];

        $fields['CHECKSUMHASH'] ??= PaytmChecksum::generate($fields, self::KEY);

        return $fields;
    }

    public function test_a_correctly_signed_callback_verifies(): void
    {
        $this->assertTrue($this->client()->verifyParams($this->paytmCallback('o1')));
    }

    public function test_a_tampered_or_unsigned_callback_does_not_verify(): void
    {
        $good = $this->paytmCallback('o1');

        $this->assertFalse($this->client()->verifyParams([...$good, 'TXNAMOUNT' => '1.00']));
        $this->assertFalse($this->client()->verifyParams([...$good, 'STATUS' => 'TXN_FAILURE']));
        $this->assertFalse($this->client()->verifyParams([...$good, 'CHECKSUMHASH' => 'forged']));

        unset($good['CHECKSUMHASH']);
        $this->assertFalse($this->client()->verifyParams($good));
        $this->assertFalse((new PaytmClient(self::MID, ''))->verifyParams($this->paytmCallback('o1')));
    }

    public function test_only_txn_success_counts_as_paid(): void
    {
        $this->assertTrue(PaytmClient::isPaid(['STATUS' => 'TXN_SUCCESS']));

        foreach (['TXN_FAILURE', 'PENDING', ''] as $status) {
            $this->assertFalse(PaytmClient::isPaid(['STATUS' => $status]), $status);
        }
    }

    private function pendingPayment(string $orderId): BotPayment
    {
        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => 'paytm',
            'api_key_enc' => self::MID,
            'webhook_secret_enc' => self::KEY,
            'status' => 'active',
        ]);

        $customer = BotCustomer::factory()->for($this->tenant)->create(['balance' => '0.00']);

        return BotPayment::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'wallet_topup',
            'customer_id' => $customer->id,
            'gateway' => 'paytm',
            'transaction_ref' => 'tu_01ABCDEFGHIJKLMNOPQRSTUVWX',
            'gateway_reference' => $orderId,
            'amount' => '2.00',
            'status' => 'pending',
        ]);
    }

    public function test_a_verified_success_credits_the_wallet_and_sends_the_customer_on(): void
    {
        $payment = $this->pendingPayment('tuorder1');

        $this->post(route('webhooks.payment.return', 'paytm'), $this->paytmCallback('tuorder1'))
            ->assertRedirect(route('payment.thanks'));

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame('2.00', (string) BotCustomer::find($payment->customer_id)->balance);
    }

    public function test_a_forged_or_failed_callback_credits_nothing(): void
    {
        $payment = $this->pendingPayment('tuorder1');

        $this->post(route('webhooks.payment.return', 'paytm'), $this->paytmCallback('tuorder1', ['CHECKSUMHASH' => 'forged']));

        $failed = $this->paytmCallback('tuorder1', ['STATUS' => 'TXN_FAILURE', 'RESPCODE' => '227', 'CHECKSUMHASH' => null]);
        $failed['CHECKSUMHASH'] = PaytmChecksum::generate(array_diff_key($failed, ['CHECKSUMHASH' => 1]), self::KEY);
        $this->post(route('webhooks.payment.return', 'paytm'), $failed);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_second_delivery_credits_only_once(): void
    {
        $payment = $this->pendingPayment('tuorder1');
        $params = $this->paytmCallback('tuorder1');

        $this->post(route('webhooks.payment.return', 'paytm'), $params);
        $this->post(route('webhooks.payment', 'paytm'), $params)->assertOk();

        $this->assertSame('2.00', (string) BotCustomer::find($payment->customer_id)->balance);
    }

    // ---- asking Paytm ----------------------------------------------------

    public function test_it_asks_paytm_for_the_order_status_with_a_signed_body(): void
    {
        Http::fake(['*/v3/order/status' => Http::response(['body' => ['resultInfo' => ['resultStatus' => 'TXN_SUCCESS']]])]);

        $this->assertSame('completed', $this->client()->checkStatus('tuorder1'));

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $payload['body'] === ['mid' => self::MID, 'orderId' => 'tuorder1']
                && PaytmChecksum::verify(json_encode($payload['body']), self::KEY, $payload['head']['signature']);
        });
    }

    public function test_failure_and_pending_are_not_completed(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['body' => ['resultInfo' => ['resultStatus' => 'TXN_FAILURE']]])
            ->push(['body' => ['resultInfo' => ['resultStatus' => 'PENDING']]])]);

        $this->assertSame('failed', $this->client()->checkStatus('o'));
        $this->assertSame('pending', $this->client()->checkStatus('o'));
    }

    // ---- wiring ----------------------------------------------------------

    public function test_the_factory_builds_it_and_bharatpe_is_listed_but_not_wired(): void
    {
        $this->assertTrue(Gateway::isReady('paytm'));

        $row = new TenantPaymentGateway(['gateway' => 'paytm', 'api_key_enc' => self::MID, 'webhook_secret_enc' => self::KEY]);
        $this->assertInstanceOf(PaytmClient::class, app(GatewayFactory::class)->make($row));

        $this->assertTrue(Gateway::exists('bharatpe'));
        $this->assertFalse(Gateway::isReady('bharatpe'));
    }
}
