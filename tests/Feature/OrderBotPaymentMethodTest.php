<?php

namespace Tests\Feature;

use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Bots\Order\OrderBotHandler;
use App\Services\Bots\Order\OrderState;
use App\Services\Payments\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBotMessenger;
use Tests\TestCase;

/**
 * Letting the customer choose how they pay.
 *
 * A reseller who has connected both mobile money and a card gateway did so
 * because their customers want both — before this, every customer was sent to
 * whichever one happened to be the default and the other was never offered.
 *
 * The risks worth testing: a menu that hides a connected gateway loses the
 * sale, and a menu that accepts a paused one takes a customer to a payment
 * nobody can complete.
 */
class OrderBotPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '255700000001';

    private Tenant $tenant;

    private FakeBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::factory()->create();

        BotCustomer::factory()->for($this->tenant)->create([
            'phone' => self::CUSTOMER,
            'balance' => '0.00',
        ]);

        $this->messenger = new FakeBotMessenger;
    }

    private function send(string $text): void
    {
        (new OrderBotHandler($this->tenant, $this->messenger))->handle(self::CUSTOMER, $text);
    }

    private function connect(string $gateway, array $attributes = []): TenantPaymentGateway
    {
        return TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => $gateway,
            'api_key_enc' => 'api-key',
            'webhook_secret_enc' => 'shhh',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    private function state(): ?string
    {
        return BotConversation::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_phone', self::CUSTOMER)
            ->value('state');
    }

    private function context(): array
    {
        return BotConversation::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_phone', self::CUSTOMER)
            ->value('context') ?? [];
    }

    /** The last list the bot sent, or null if the last message was not one. */
    private function lastList(): ?array
    {
        $last = end($this->messenger->sent);

        return ($last && $last['type'] === 'list') ? $last : null;
    }

    /** @return array<int, string> the gateway codes offered, in order */
    private function offered(): array
    {
        return array_map(
            fn (array $row) => substr($row['id'], strlen('pay:')),
            $this->lastList()['rows'] ?? [],
        );
    }

    /** Walk from the menu to the point where a payment method is needed. */
    private function askToTopUp(string $amount = '10'): void
    {
        $this->send('hi');
        $this->send('main:topup');
        $this->send($amount);
    }

    private function fakeGatewayAccepts(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => ['reference' => 'gw-ref-1'],
            ]),
        ]);
    }

    // ---- when the choice is offered --------------------------------------

    public function test_two_gateways_are_both_offered(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus');

        $this->askToTopUp();

        $this->assertSame(OrderState::SelectGateway->value, $this->state());
        $this->assertEqualsCanonicalizing(['snippe', 'cryptomus'], $this->offered());
    }

    /**
     * The reseller's default is the one they want used, so it leads — but the
     * others are still there, which is the whole point.
     */
    public function test_the_default_gateway_is_listed_first(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus')->makeDefault();

        $this->askToTopUp();

        $this->assertSame('cryptomus', $this->offered()[0]);
    }

    /** One gateway is no choice at all, so the customer is not stopped to make it. */
    public function test_a_single_gateway_is_used_without_asking(): void
    {
        $this->connect('snippe');

        $this->askToTopUp();

        $this->assertSame(OrderState::TopupPhone->value, $this->state());
    }

    public function test_a_paused_gateway_is_not_offered(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus', ['status' => 'inactive']);

        $this->askToTopUp();

        // Only one left that works, so it goes straight there.
        $this->assertSame(OrderState::TopupPhone->value, $this->state());
    }

    /**
     * A gateway with no client behind it cannot take a payment, so offering it
     * would be offering a dead end.
     */
    public function test_an_unwired_gateway_is_not_offered(): void
    {
        $unwired = collect(array_keys(Gateway::all()))
            ->first(fn (string $code) => ! Gateway::isReady($code));

        if ($unwired === null) {
            $this->markTestSkipped('Every configured gateway is wired up.');
        }

        $this->connect('snippe');
        $this->connect('cryptomus');
        $this->connect($unwired);

        $this->askToTopUp();

        $this->assertNotContains($unwired, $this->offered());
    }

    public function test_a_reseller_with_no_gateway_is_told_so(): void
    {
        $this->askToTopUp();

        $this->assertStringContainsString('payment', strtolower($this->messenger->lastBody()));
        $this->assertSame(0, BotPayment::withoutTenantScope()->count());
    }

    // ---- acting on the choice --------------------------------------------

    /** Mobile money pushes to a handset, so the number is asked for after. */
    public function test_choosing_mobile_money_asks_for_a_phone(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus');

        $this->askToTopUp();
        $this->send('pay:snippe');

        $this->assertSame(OrderState::TopupPhone->value, $this->state());
        $this->assertSame('snippe', $this->context()['pay_gateway']);
    }

    /** The customer's pick is what gets charged, not the reseller's default. */
    public function test_the_chosen_gateway_is_the_one_charged(): void
    {
        $this->connect('snippe')->makeDefault();
        $this->connect('cryptomus');

        Http::fake([
            '*' => Http::response([
                'state' => 0,
                'result' => ['uuid' => 'cm-1', 'url' => 'https://pay.cryptomus.com/x'],
            ]),
        ]);

        $this->askToTopUp();
        $this->send('pay:cryptomus');

        $this->assertSame(
            'cryptomus',
            BotPayment::withoutTenantScope()->value('gateway'),
        );
    }

    public function test_the_amount_survives_the_choice(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus');
        $this->fakeGatewayAccepts();

        $this->askToTopUp('42');
        $this->send('pay:snippe');
        $this->send(self::CUSTOMER);

        $this->assertSame(
            0,
            bccomp('42.00', (string) BotPayment::withoutTenantScope()->value('amount'), 2),
        );
    }

    // ---- a choice that is no longer valid --------------------------------

    /**
     * A list reply can arrive minutes after it was sent, by which time the
     * reseller may have paused that gateway. Trusting the reply would start a
     * payment against credentials that are no longer meant to be used.
     */
    public function test_a_gateway_paused_after_the_menu_was_sent_is_refused(): void
    {
        $this->connect('snippe');
        $cryptomus = $this->connect('cryptomus');

        $this->askToTopUp();
        $cryptomus->update(['status' => 'inactive']);

        $this->send('pay:cryptomus');

        $this->assertSame(0, BotPayment::withoutTenantScope()->count());
        $this->assertSame(OrderState::SelectGateway->value, $this->state());
    }

    /** A gateway this reseller never connected must not be reachable by name. */
    public function test_a_gateway_the_reseller_never_connected_is_refused(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus');

        $this->askToTopUp();
        $this->send('pay:stripe');

        $this->assertSame(0, BotPayment::withoutTenantScope()->count());
    }

    /**
     * Asked again rather than dropped to the main menu: the customer is one tap
     * from paying and the amount is still in context.
     */
    public function test_an_unrecognised_reply_re_offers_the_menu(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus');

        $this->askToTopUp();
        $this->send('something else entirely');

        $this->assertSame(OrderState::SelectGateway->value, $this->state());
        $this->assertEqualsCanonicalizing(['snippe', 'cryptomus'], $this->offered());
    }

    /**
     * Between the menu being sent and the reply arriving, the reseller may have
     * removed every gateway — the customer must be told rather than looped.
     */
    public function test_losing_every_gateway_mid_choice_ends_the_conversation(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus');

        $this->askToTopUp();

        TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->update(['status' => 'inactive']);

        $this->send('pay:snippe');

        $this->assertSame(0, BotPayment::withoutTenantScope()->count());
        $this->assertNotSame(OrderState::SelectGateway->value, $this->state());
    }

    /**
     * WhatsApp silently drops a list longer than ten rows, which would hide the
     * gateways at the end rather than fail visibly.
     */
    public function test_the_menu_is_capped_at_what_whatsapp_will_render(): void
    {
        $ready = collect(array_keys(Gateway::all()))
            ->filter(fn (string $code) => Gateway::isReady($code));

        if ($ready->count() <= 10) {
            $this->markTestSkipped('Fewer ready gateways than the list cap.');
        }

        $ready->each(fn (string $code) => $this->connect($code));

        $this->askToTopUp();

        $this->assertLessThanOrEqual(10, count($this->offered()));
    }
}
