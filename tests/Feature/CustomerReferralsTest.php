<?php

namespace Tests\Feature;

use App\Actions\Payments\CompleteTopup;
use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Services\Bots\BotSettings;
use App\Services\Customers\CustomerReferrals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer-to-customer referrals: one shopper invites another and earns a cut
 * of their first top-up.
 *
 * This arrived half-built from the old platform — codes were generated and the
 * bot read the totals, but nothing ever set `referred_by`, so no bonus could
 * ever be paid. These tests cover the whole chain, and especially the two
 * places money could be paid twice.
 */
class CustomerReferralsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function customer(array $attributes = []): BotCustomer
    {
        return BotCustomer::factory()->for($this->tenant)->create($attributes);
    }

    private function setReferralPercent(float $percent): void
    {
        BotSettings::save($this->tenant->id, 'order', [
            'shop' => ['referral_percent' => $percent],
        ]);
    }

    // --- Codes ----------------------------------------------------------

    public function test_a_customer_is_given_a_code(): void
    {
        $customer = $this->customer(['referral_code' => null]);

        $code = CustomerReferrals::assignCode($customer);

        $this->assertNotSame('', $code);
        $this->assertSame($code, $customer->fresh()->referral_code);
    }

    public function test_an_existing_code_is_never_replaced(): void
    {
        $customer = $this->customer(['referral_code' => 'CABC1234']);

        $this->assertSame('CABC1234', CustomerReferrals::assignCode($customer));
    }

    /** Codes get read aloud and typed back, so the confusable pairs are out. */
    public function test_codes_avoid_characters_people_misread(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $code = CustomerReferrals::assignCode($this->customer(['referral_code' => null]));

            $this->assertDoesNotMatchRegularExpression(
                '/[OIS015B8]/',
                substr($code, 1),
                "code {$code} contains a confusable character",
            );
        }
    }

    // --- Claiming -------------------------------------------------------

    public function test_a_customer_can_be_linked_to_their_referrer(): void
    {
        $referrer = $this->customer(['referral_code' => 'CREF7777']);
        $newcomer = $this->customer(['referral_code' => 'CNEW2222']);

        $this->assertTrue(CustomerReferrals::claim($newcomer, 'CREF7777'));
        $this->assertSame($referrer->id, $newcomer->fresh()->referred_by);
    }

    public function test_a_code_is_matched_regardless_of_case(): void
    {
        $referrer = $this->customer(['referral_code' => 'CREF7777']);
        $newcomer = $this->customer(['referral_code' => 'CNEW2222']);

        $this->assertTrue(CustomerReferrals::claim($newcomer, '  cref7777 '));
        $this->assertSame($referrer->id, $newcomer->fresh()->referred_by);
    }

    public function test_a_customer_cannot_refer_themselves(): void
    {
        $customer = $this->customer(['referral_code' => 'CSELF999']);

        $this->assertFalse(CustomerReferrals::claim($customer, 'CSELF999'));
        $this->assertNull($customer->fresh()->referred_by);
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $customer = $this->customer(['referral_code' => 'CNEW2222']);

        $this->assertFalse(CustomerReferrals::claim($customer, 'CNOPE111'));
        $this->assertNull($customer->fresh()->referred_by);
    }

    /** A referrer belongs to one reseller's shop; codes must not cross. */
    public function test_a_code_from_another_resellers_shop_is_refused(): void
    {
        $otherTenant = Tenant::factory()->create();
        BotCustomer::factory()->for($otherTenant)->create(['referral_code' => 'CTHEIRS1']);

        $mine = $this->customer(['referral_code' => 'CMINE111']);

        $this->assertFalse(CustomerReferrals::claim($mine, 'CTHEIRS1'));
        $this->assertNull($mine->fresh()->referred_by);
    }

    public function test_a_customer_can_only_be_claimed_once(): void
    {
        $first = $this->customer(['referral_code' => 'CFIRST11']);
        $second = $this->customer(['referral_code' => 'CSECOND1']);
        $newcomer = $this->customer(['referral_code' => 'CNEW2222']);

        $this->assertTrue(CustomerReferrals::claim($newcomer, 'CFIRST11'));
        $this->assertFalse(CustomerReferrals::claim($newcomer, 'CSECOND1'));

        $this->assertSame($first->id, $newcomer->fresh()->referred_by);
    }

    // --- Paying the bonus ------------------------------------------------

    public function test_the_referrer_earns_a_cut_of_the_first_top_up(): void
    {
        $this->setReferralPercent(10);

        $referrer = $this->customer(['referral_code' => 'CREF7777', 'balance' => '0.00']);
        $newcomer = $this->customer(['referred_by' => $referrer->id, 'first_deposit_done' => false]);

        CustomerReferrals::payFirstDepositBonus($newcomer, '20.00');

        $fresh = $referrer->fresh();
        $this->assertSame('2.00', $fresh->balance);
        $this->assertSame('2.00', $fresh->referral_earnings);
    }

    /**
     * The bonus lands in the wallet AND in the running earnings total. If the
     * two drift apart the customer's own referral screen starts lying.
     */
    public function test_the_bonus_moves_balance_and_earnings_together(): void
    {
        $this->setReferralPercent(50);

        $referrer = $this->customer([
            'referral_code' => 'CREF7777',
            'balance' => '5.00',
            'referral_earnings' => '1.00',
        ]);
        $newcomer = $this->customer(['referred_by' => $referrer->id, 'first_deposit_done' => false]);

        CustomerReferrals::payFirstDepositBonus($newcomer, '4.00');

        $fresh = $referrer->fresh();
        $this->assertSame('7.00', $fresh->balance);
        $this->assertSame('3.00', $fresh->referral_earnings);
    }

    /**
     * Gateways retry webhooks for hours. Paying the bonus on each retry would
     * hand the referrer free money for one deposit.
     */
    public function test_the_bonus_is_paid_only_once_however_often_it_is_called(): void
    {
        $this->setReferralPercent(10);

        $referrer = $this->customer(['referral_code' => 'CREF7777', 'balance' => '0.00']);
        $newcomer = $this->customer(['referred_by' => $referrer->id, 'first_deposit_done' => false]);

        foreach (range(1, 5) as $ignored) {
            CustomerReferrals::payFirstDepositBonus($newcomer->fresh(), '20.00');
        }

        $this->assertSame('2.00', $referrer->fresh()->balance);
    }

    public function test_a_second_deposit_earns_nothing(): void
    {
        $this->setReferralPercent(10);

        $referrer = $this->customer(['referral_code' => 'CREF7777', 'balance' => '0.00']);
        $newcomer = $this->customer(['referred_by' => $referrer->id, 'first_deposit_done' => true]);

        CustomerReferrals::payFirstDepositBonus($newcomer, '50.00');

        $this->assertSame('0.00', $referrer->fresh()->balance);
    }

    public function test_an_unreferred_customer_pays_nobody(): void
    {
        $this->setReferralPercent(10);

        $customer = $this->customer(['referred_by' => null, 'first_deposit_done' => false]);

        CustomerReferrals::payFirstDepositBonus($customer, '20.00');

        // The deposit is still marked, so a later referral cannot backdate it.
        $this->assertTrue($customer->fresh()->first_deposit_done);
    }

    public function test_no_bonus_when_the_reseller_has_not_set_a_percentage(): void
    {
        $this->setReferralPercent(0);

        $referrer = $this->customer(['referral_code' => 'CREF7777', 'balance' => '0.00']);
        $newcomer = $this->customer(['referred_by' => $referrer->id, 'first_deposit_done' => false]);

        CustomerReferrals::payFirstDepositBonus($newcomer, '20.00');

        $this->assertSame('0.00', $referrer->fresh()->balance);
    }

    /** A percentage that rounds below a cent pays nothing rather than 0.00. */
    public function test_a_bonus_that_rounds_to_nothing_is_not_paid(): void
    {
        $this->setReferralPercent(1);

        $referrer = $this->customer(['referral_code' => 'CREF7777', 'balance' => '0.00']);
        $newcomer = $this->customer(['referred_by' => $referrer->id, 'first_deposit_done' => false]);

        CustomerReferrals::payFirstDepositBonus($newcomer, '0.20');

        $this->assertSame('0.00', $referrer->fresh()->referral_earnings);
    }

    // --- Wired into the payment flow -------------------------------------

    /**
     * The whole point: a confirmed top-up must actually pay the referrer.
     * This is the wiring that was missing entirely.
     */
    public function test_a_confirmed_top_up_pays_the_referrer(): void
    {
        $this->setReferralPercent(10);

        $referrer = $this->customer(['referral_code' => 'CREF7777', 'balance' => '0.00']);
        $newcomer = $this->customer([
            'referred_by' => $referrer->id,
            'first_deposit_done' => false,
            'balance' => '0.00',
        ]);

        $payment = BotPayment::factory()->for($this->tenant)->create([
            'customer_id' => $newcomer->id,
            'amount' => '30.00',
            'status' => 'pending',
        ]);

        app(CompleteTopup::class)->handle($payment);

        $this->assertSame('30.00', $newcomer->fresh()->balance);
        $this->assertSame('3.00', $referrer->fresh()->balance);
    }

    public function test_a_retried_webhook_does_not_pay_the_referrer_twice(): void
    {
        $this->setReferralPercent(10);

        $referrer = $this->customer(['referral_code' => 'CREF7777', 'balance' => '0.00']);
        $newcomer = $this->customer([
            'referred_by' => $referrer->id,
            'first_deposit_done' => false,
            'balance' => '0.00',
        ]);

        $payment = BotPayment::factory()->for($this->tenant)->create([
            'customer_id' => $newcomer->id,
            'amount' => '30.00',
            'status' => 'pending',
        ]);

        foreach (range(1, 4) as $ignored) {
            app(CompleteTopup::class)->handle($payment->fresh());
        }

        $this->assertSame('30.00', $newcomer->fresh()->balance);
        $this->assertSame('3.00', $referrer->fresh()->balance);
    }

    public function test_counting_a_customers_referrals(): void
    {
        $referrer = $this->customer(['referral_code' => 'CREF7777']);

        $this->customer(['referred_by' => $referrer->id]);
        $this->customer(['referred_by' => $referrer->id]);
        $this->customer(['referred_by' => null]);

        $this->assertSame(2, CustomerReferrals::countFor($referrer));
    }
}
