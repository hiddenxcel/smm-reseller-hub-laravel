<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\BotCustomer;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotHandlerFactory;
use App\Services\Bots\BotRouter;
use App\Services\Bots\InboundMessage;
use App\Services\Bots\Order\OrderBotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBotMessenger;
use Tests\Support\FakeBotMessengerFactory;
use Tests\Support\FakeOrderBotHandler;
use Tests\Support\FakeSupportBotHandler;
use Tests\TestCase;

/**
 * The bot greets a customer by their first name, taken from the name on their
 * WhatsApp profile — which Meta sends with every message and we never kept, so
 * every welcome said "THERE".
 */
class CustomerFirstNameTest extends TestCase
{
    use RefreshDatabase;

    // ---- the first name --------------------------------------------------

    private function first(?string $name): ?string
    {
        return (new BotCustomer(['name' => $name]))->firstName();
    }

    public function test_the_first_word_of_the_name_is_used(): void
    {
        $this->assertSame('Juma', $this->first('Juma Hassan'));
        $this->assertSame('Asha', $this->first('Asha'));
    }

    public function test_capitals_are_normalised(): void
    {
        $this->assertSame('Juma', $this->first('JUMA HASSAN'));
        $this->assertSame('Juma', $this->first('juma'));
    }

    public function test_decoration_around_the_name_is_stripped(): void
    {
        $this->assertSame('Asha', $this->first('★ Asha ★'));
        $this->assertSame('Juma', $this->first('Juma 🔥 Hassan'));
        $this->assertSame('Neema', $this->first('~Neema~'));
        $this->assertSame('Hamisi', $this->first('"Hamisi" Said'));
    }

    public function test_a_name_with_an_apostrophe_or_a_hyphen_keeps_it(): void
    {
        $this->assertSame("O'brien", $this->first("O'Brien Tom"));
        $this->assertSame('Anne-Marie', $this->first('Anne-Marie Dubois'));
    }

    public function test_names_in_other_scripts_work(): void
    {
        $this->assertSame('Müller', $this->first('Müller Hans'));
        $this->assertSame('محمد', $this->first('محمد علي'));
        $this->assertSame('राहुल', $this->first('राहुल शर्मा'));
    }

    public function test_nothing_worth_greeting_gives_null(): void
    {
        $this->assertNull($this->first(null));
        $this->assertNull($this->first(''));
        $this->assertNull($this->first('   '));
        $this->assertNull($this->first('🔥🔥🔥'));
        $this->assertNull($this->first('12345'));
    }

    // ---- reading it from Meta -------------------------------------------

    private function payload(?string $name): array
    {
        return ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'pn-1'],
            'contacts' => $name === null ? [] : [['profile' => ['name' => $name], 'wa_id' => '255700000001']],
            'messages' => [['from' => '255700000001', 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'hi']]],
        ]]]]]];
    }

    public function test_the_profile_name_is_read_from_the_webhook(): void
    {
        $message = InboundMessage::fromMetaPayload($this->payload('Juma Hassan'));

        $this->assertSame('Juma Hassan', $message->profileName);
    }

    public function test_a_payload_without_a_profile_name_still_parses(): void
    {
        $message = InboundMessage::fromMetaPayload($this->payload(null));

        $this->assertNotNull($message);
        $this->assertNull($message->profileName);
    }

    public function test_the_name_is_cleaned_before_it_is_kept(): void
    {
        $this->assertSame('Juma Hassan', InboundMessage::cleanName("  Juma \n\t Hassan\u{0000} "));
        $this->assertSame(80, mb_strlen(InboundMessage::cleanName(str_repeat('a', 200))));
        $this->assertNull(InboundMessage::cleanName('   '));
        $this->assertNull(InboundMessage::cleanName(null));
        $this->assertNull(InboundMessage::cleanName(['not', 'a', 'string']));
    }

    // ---- remembering it --------------------------------------------------

    private function route(Tenant $tenant, TenantWhatsApp $number, ?string $profileName, string $from = '255700000001'): void
    {
        Subscription::factory()->for($tenant)->active()->create(['service_key' => ServiceKey::OrderBot]);

        $handlers = new BotHandlerFactory;
        $handlers->register('order', FakeOrderBotHandler::class);
        $handlers->register('support', FakeSupportBotHandler::class);

        (new BotRouter(new FakeBotMessengerFactory, $handlers))->route(new InboundMessage(
            phoneNumberId: $number->phone_number_id,
            from: $from,
            text: 'hi',
            providerMessageId: 'wamid.TEST',
            profileName: $profileName,
        ));
    }

    private function setUpShop(): array
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();

        return [$tenant, $number];
    }

    public function test_a_new_customers_name_is_saved_from_their_first_message(): void
    {
        [$tenant, $number] = $this->setUpShop();

        $this->route($tenant, $number, 'Juma Hassan');

        $customer = BotCustomer::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($customer);
        $this->assertSame('Juma Hassan', $customer->name);
        $this->assertSame('255700000001', $customer->phone);
    }

    public function test_an_existing_customer_without_a_name_gets_one(): void
    {
        [$tenant, $number] = $this->setUpShop();
        $customer = BotCustomer::factory()->for($tenant)->create(['phone' => '255700000001', 'name' => null]);

        $this->route($tenant, $number, 'Asha Mwangi');

        $this->assertSame('Asha Mwangi', $customer->fresh()->name);
    }

    public function test_a_name_already_on_file_is_never_overwritten(): void
    {
        [$tenant, $number] = $this->setUpShop();
        $customer = BotCustomer::factory()->for($tenant)->create(['phone' => '255700000001', 'name' => 'Set By Reseller']);

        $this->route($tenant, $number, 'Something Else');

        $this->assertSame('Set By Reseller', $customer->fresh()->name);
    }

    public function test_no_profile_name_creates_no_customer_and_changes_nothing(): void
    {
        [$tenant, $number] = $this->setUpShop();

        $this->route($tenant, $number, null);

        $this->assertSame(0, BotCustomer::withoutTenantScope()->where('tenant_id', $tenant->id)->count());
    }

    public function test_one_resellers_customer_is_not_touched_by_anothers(): void
    {
        [$tenant, $number] = $this->setUpShop();
        $other = Tenant::factory()->create();
        $theirs = BotCustomer::factory()->for($other)->create(['phone' => '255700000001', 'name' => null]);

        $this->route($tenant, $number, 'Juma Hassan');

        $this->assertNull($theirs->fresh()->name);
    }

    // ---- the greeting ----------------------------------------------------

    private function welcome(?string $customerName): string
    {
        Queue::fake();

        $tenant = Tenant::factory()->create(['business_name' => 'Kuza Panel']);
        BotCustomer::factory()->for($tenant)->create(['phone' => '255700000001', 'name' => $customerName]);

        $messenger = new FakeBotMessenger;
        (new OrderBotHandler($tenant, $messenger))->handle('255700000001', 'hi');

        return $messenger->sent[0]['body'];
    }

    public function test_the_welcome_greets_the_customer_by_first_name(): void
    {
        $body = $this->welcome('Juma Hassan');

        $this->assertStringContainsString('WELCOME, JUMA!', $body);
        $this->assertStringContainsString('Hello Juma!', $body);
        $this->assertStringNotContainsString('Hassan', $body);
    }

    public function test_a_customer_with_no_usable_name_is_greeted_neutrally(): void
    {
        $this->assertStringContainsString('WELCOME, THERE!', $this->welcome(null));
        $this->assertStringContainsString('WELCOME, THERE!', $this->welcome('🔥🔥'));
    }
}
