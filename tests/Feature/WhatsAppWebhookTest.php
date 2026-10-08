<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\BotMessage;
use App\Models\BsuidAlias;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The front door: every reseller's WhatsApp traffic arrives here, and the
 * only thing standing between the public internet and their bots is the
 * signature check.
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'meta-app-secret';

    private const VERIFY_TOKEN = 'my-verify-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_secret' => self::APP_SECRET,
            'services.meta.verify_token' => self::VERIFY_TOKEN,
        ]);

        // The bot replies over the Cloud API; nothing should actually leave.
        Http::fake();
    }

    private function payload(string $phoneNumberId, string $text = 'hi'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'id' => 'wamid.TEST',
                            'from' => '255700000001',
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function postSigned(array $payload, ?string $secret = null)
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret ?? self::APP_SECRET);

        return $this->call(
            method: 'POST',
            uri: '/webhooks/whatsapp',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            content: $body,
        );
    }

    private function activeNumber(): TenantWhatsApp
    {
        $tenant = Tenant::factory()->create();

        Subscription::factory()->for($tenant)->active()->create([
            'service_key' => ServiceKey::OrderBot,
        ]);

        return TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();
    }

    // ---- the verification handshake ---------------------------------------

    public function test_it_echoes_the_challenge_for_the_right_verify_token(): void
    {
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token='
            .self::VERIFY_TOKEN.'&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    public function test_it_refuses_the_handshake_with_a_wrong_token(): void
    {
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
            ->assertForbidden();
    }

    public function test_it_refuses_the_handshake_when_no_token_is_configured(): void
    {
        config(['services.meta.verify_token' => '']);

        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=&hub_challenge=12345')
            ->assertForbidden();
    }

    // ---- signature verification -------------------------------------------

    public function test_a_correctly_signed_payload_is_accepted(): void
    {
        $number = $this->activeNumber();

        $this->postSigned($this->payload($number->phone_number_id))
            ->assertOk()
            ->assertSee('handled_order');
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $number = $this->activeNumber();

        $this->postJson('/webhooks/whatsapp', $this->payload($number->phone_number_id))
            ->assertUnauthorized();

        $this->assertDatabaseCount('bot_messages', 0);
    }

    public function test_a_wrongly_signed_request_is_rejected(): void
    {
        $number = $this->activeNumber();

        $this->postSigned($this->payload($number->phone_number_id), secret: 'not-the-secret')
            ->assertUnauthorized();

        $this->assertDatabaseCount('bot_messages', 0);
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $number = $this->activeNumber();

        // Sign one body, send another.
        $signed = json_encode($this->payload($number->phone_number_id, 'hi'));
        $tampered = json_encode($this->payload($number->phone_number_id, 'something else'));

        $this->call(
            method: 'POST',
            uri: '/webhooks/whatsapp',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $signed, self::APP_SECRET),
            ],
            content: $tampered,
        )->assertUnauthorized();
    }

    public function test_it_rejects_everything_when_no_app_secret_is_configured(): void
    {
        // Fail closed: with no secret there is nothing to verify against, and
        // an open webhook would let anyone drive any reseller's bot.
        config(['services.meta.app_secret' => '']);

        $number = $this->activeNumber();

        $this->postSigned($this->payload($number->phone_number_id), secret: '')
            ->assertUnauthorized();
    }

    // ---- routing behaviour ------------------------------------------------

    public function test_it_reaches_the_bot_and_logs_the_message(): void
    {
        $number = $this->activeNumber();

        $this->postSigned($this->payload($number->phone_number_id, 'hello'))->assertOk();

        $this->assertDatabaseHas('bot_messages', [
            'tenant_id' => $number->tenant_id,
            'customer_phone' => '255700000001',
            'direction' => 'in',
            'message' => 'hello',
        ]);
    }

    // ---- people who hide their phone number -----------------------------------

    private function hiddenNumberPayload(string $phoneNumberId, string $text = 'hi', string $bsuid = 'US.13491208655302741918'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'contacts' => [[
                            'profile' => ['name' => 'Valeria', 'username' => 'valeria.tz'],
                            'user_id' => $bsuid,
                        ]],
                        'messages' => [[
                            'id' => 'wamid.HIDDEN',
                            'from_user_id' => $bsuid,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_a_sender_with_no_phone_number_reaches_the_bot_under_a_short_alias(): void
    {
        $number = $this->activeNumber();

        $this->postSigned($this->hiddenNumberPayload($number->phone_number_id, 'hello'))
            ->assertOk()
            ->assertSee('handled_order');

        $row = BotMessage::withoutTenantScope()->where('direction', 'in')->first();

        // Short enough for every phone column, and plainly not a phone number.
        $this->assertMatchesRegularExpression('/^u[0-9a-f]{20}$/', $row->customer_phone);
        $this->assertSame('hello', $row->message);
        $this->assertDatabaseHas('bot_customers', ['tenant_id' => $number->tenant_id, 'phone' => $row->customer_phone, 'name' => 'Valeria']);
    }

    public function test_the_reply_goes_to_the_id_not_to_a_phone(): void
    {
        $number = $this->activeNumber();

        $this->postSigned($this->hiddenNumberPayload($number->phone_number_id, 'hello'))->assertOk();

        $sent = Http::recorded(fn (Request $request) => str_contains($request->url(), '/messages') && isset($request['recipient']));

        $this->assertNotEmpty($sent);
        [$request] = $sent->first();
        $this->assertSame('US.13491208655302741918', $request['recipient']);
        $this->assertArrayNotHasKey('to', $request->data());
    }

    public function test_the_same_id_is_always_the_same_customer(): void
    {
        $number = $this->activeNumber();

        $this->postSigned($this->hiddenNumberPayload($number->phone_number_id, 'one'))->assertOk();
        $this->postSigned($this->hiddenNumberPayload($number->phone_number_id, 'two'))->assertOk();

        $phones = BotMessage::withoutTenantScope()->where('direction', 'in')->pluck('customer_phone')->unique();

        $this->assertCount(1, $phones);
        $this->assertSame(1, BsuidAlias::withoutTenantScope()->count());
        $this->assertSame('valeria.tz', BsuidAlias::withoutTenantScope()->first()->username);
    }

    public function test_an_alias_means_nothing_to_another_reseller(): void
    {
        $number = $this->activeNumber();
        $this->postSigned($this->hiddenNumberPayload($number->phone_number_id, 'hello'))->assertOk();

        $alias = BsuidAlias::withoutTenantScope()->first()->alias;
        $other = Tenant::factory()->create();

        $this->assertNull(BsuidAlias::bsuidFor($other->id, $alias));
        $this->assertSame('US.13491208655302741918', BsuidAlias::bsuidFor($number->tenant_id, $alias));
        $this->assertNull(BsuidAlias::bsuidFor($number->tenant_id, '255700000001'));
    }

    public function test_a_phone_number_is_still_used_when_meta_sends_one(): void
    {
        $number = $this->activeNumber();
        $payload = $this->payload($number->phone_number_id, 'hello');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['from_user_id'] = 'US.999';

        $this->postSigned($payload)->assertOk();

        $this->assertDatabaseHas('bot_messages', ['customer_phone' => '255700000001', 'direction' => 'in']);
        $this->assertSame(0, BsuidAlias::withoutTenantScope()->count());
    }
    public function test_a_status_callback_carries_no_message_and_is_acknowledged(): void
    {
        // Meta sends delivery and read receipts to the same URL.
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '123'],
                        'statuses' => [['id' => 'wamid.X', 'status' => 'delivered']],
                    ],
                ]],
            ]],
        ];

        $this->postSigned($payload)->assertOk()->assertSee('no_message');
    }

    public function test_a_message_without_a_sender_is_acknowledged_and_its_shape_is_logged(): void
    {
        // A sender Meta identifies without a phone number must not vanish as
        // if it were a delivery receipt: the log says what arrived, without
        // its content.
        $payload = $this->payload('123', 'a private message');
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['from']);

        Log::spy();

        $this->postSigned($payload)->assertOk()->assertSee('no_message');

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            return str_contains($message, 'could not be read')
                && $context['has_from'] === false
                && in_array('type', $context['message_keys'], true)
                && ! str_contains(json_encode($context), 'a private message');
        })->once();
    }

    public function test_a_receipt_logs_nothing_about_unreadable_messages(): void
    {
        Log::spy();

        $this->postSigned(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '123'],
            'statuses' => [['id' => 'wamid.X', 'status' => 'read']],
        ]]]]]])->assertOk();

        Log::shouldNotHaveReceived('warning');
    }
    public function test_an_unknown_number_is_acknowledged_not_errored(): void
    {
        // Still a 200: a non-200 makes Meta retry, and retrying will not make
        // the number any more known.
        $this->postSigned($this->payload('never-seen-this'))
            ->assertOk()
            ->assertSee('unknown_number');
    }

    public function test_a_locked_gate_is_acknowledged(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();

        $this->postSigned($this->payload($number->phone_number_id))
            ->assertOk()
            ->assertSee('gate_locked');
    }

    public function test_the_webhook_needs_no_csrf_token(): void
    {
        // It lives outside the web middleware group; a CSRF failure would
        // show up as a 419 here.
        $number = $this->activeNumber();

        $this->postSigned($this->payload($number->phone_number_id))
            ->assertOk();
    }
}
