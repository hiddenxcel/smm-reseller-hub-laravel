<?php

namespace App\Http\Controllers;

use App\Enums\ServiceKey;
use App\Models\PlatformNumber;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Services\Billing\Checkout;
use App\Services\Billing\PlatformGateways;
use App\Services\Billing\Pricing;
use App\Services\Payments\PaymentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * What a reseller pays US.
 *
 * The other direction from every payment screen built so far: Gateways,
 * Payments and the top-up flow are a reseller taking money from their own
 * customers. This is the SaaS subscription, and it runs on the platform's own
 * merchant accounts — which is why it goes through PlatformGateways rather
 * than GatewayFactory. Conflating the two would have a reseller's
 * subscription paid into their own account.
 *
 * The engine underneath (Pricing, Checkout, ActivatePurchase) was built and
 * tested before this screen existed; nothing here does arithmetic of its own.
 */
class BillingController extends Controller
{
    public function __construct(private Checkout $checkout) {}

    /**
     * What you own, what it costs, and when it runs out.
     *
     * Expiry leads. A reseller opening this page is usually asking one of two
     * questions — "when does this stop working?" or "why has it stopped?" —
     * and both are answered by the dates.
     */
    public function index(Request $request): Response
    {
        $tenant = $request->user();
        $tenantId = (int) $tenant->id;

        return Inertia::render('Billing/Index', [
            'services' => $this->services($tenantId),
            'terms' => Pricing::terms(),
            'gateways' => PlatformGateways::available(),
            'currency' => (string) config('billing.currency', 'USD'),
            'credit' => (float) $tenant->referral_credit,
            'numbers' => $this->rentableNumbers(),
            'invoices' => $this->invoices($tenantId),
        ]);
    }

    /**
     * Every sellable service with what the reseller currently holds.
     *
     * Sandbox is reported apart from active and locked: it is not a paid
     * subscription, but it is not nothing either — the bot answers the
     * reseller's own test numbers — and a reseller deciding whether to pay
     * needs to know which of the three they are in.
     */
    private function services(int $tenantId): array
    {
        $subscriptions = Subscription::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy(fn (Subscription $subscription) => $subscription->service_key instanceof ServiceKey
                ? $subscription->service_key->value
                : (string) $subscription->service_key);

        $states = Subscription::stateMap($tenantId);

        return collect(Pricing::sellableServices())
            ->map(function (array $service) use ($subscriptions, $states) {
                $subscription = $subscriptions->get($service['key']);
                $endsAt = $subscription?->ends_at;

                return [
                    ...$service,
                    'state' => $states[$service['key']] ?? 'locked',
                    'endsAt' => $endsAt?->toIso8601String(),
                    // Counted server-side: a browser with a wrong clock would
                    // otherwise tell a reseller their subscription is fine.
                    'daysLeft' => $endsAt === null ? null : $this->daysLeft($endsAt),
                    // Prices for every term, so switching the term on the page
                    // needs no round trip.
                    'termPrices' => $this->termPrices($service['key']),
                ];
            })
            ->all();
    }

    /** @return array<string, int> months => total in cents */
    private function termPrices(string $service): array
    {
        $prices = [];

        foreach (Pricing::terms() as $term) {
            $prices[(string) $term['months']] = Pricing::serviceTotal($service, $term['months']);
        }

        return $prices;
    }

    /**
     * Negative once it has lapsed, rather than clamped to zero — "expired 3
     * days ago" and "expires today" are different problems.
     */
    private function daysLeft(Carbon $endsAt): int
    {
        return (int) now()->startOfDay()->diffInDays($endsAt->copy()->startOfDay(), false);
    }

    /**
     * Numbers a reseller can rent, if they have no WhatsApp number of their
     * own. Only what is genuinely free right now — a number listed here and
     * taken by the time payment clears fails loudly in ActivatePurchase.
     */
    private function rentableNumbers(): array
    {
        return PlatformNumber::where('status', 'available')
            ->orderBy('id')
            ->get(['id', 'display_number', 'monthly_cost'])
            ->map(fn (PlatformNumber $number) => [
                'id' => $number->id,
                'displayNumber' => $number->display_number,
                'cost' => Pricing::toCents($number->monthly_cost),
            ])
            ->all();
    }

    /**
     * Payment history, newest first.
     *
     * Pending rows are shown too, not filtered out: a reseller who paid and is
     * waiting on a crypto confirmation needs to see that we know about it.
     */
    private function invoices(int $tenantId): array
    {
        return SubscriptionPayment::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (SubscriptionPayment $payment) => [
                'id' => $payment->id,
                'reference' => $payment->transaction_ref,
                'gateway' => $payment->gateway,
                'amount' => (float) $payment->amount,
                'creditApplied' => (float) $payment->credit_applied,
                'currency' => $payment->currency,
                'months' => $payment->months,
                'status' => $payment->status,
                'items' => $this->describeItems($payment),
                'at' => $payment->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * What an invoice bought, in words.
     *
     * Read from the stored cart rather than from today's plans: a plan renamed
     * or repriced since must not rewrite an invoice the reseller already paid.
     */
    private function describeItems(SubscriptionPayment $payment): array
    {
        return collect($payment->items ?? [])
            ->map(fn (array $item) => match ($item['type'] ?? '') {
                'service' => ucwords(str_replace('_', ' ', (string) ($item['key'] ?? ''))),
                'number' => 'Rented number',
                default => 'Item',
            })
            ->all();
    }

    /**
     * Start a checkout: price the cart, record a pending invoice, and send the
     * reseller to the gateway.
     *
     * Nothing is granted here. The invoice buys nothing until the gateway
     * confirms it and ActivatePurchase replays the cart — which is what stops
     * an abandoned checkout handing out a free month.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'services' => ['required', 'array', 'min:1'],
            'services.*' => [Rule::in(config('billing.sellable', []))],
            'months' => ['required', 'integer', Rule::in(array_column(Pricing::terms(), 'months'))],
            'gateway' => ['required', 'string'],
            'numberId' => ['nullable', 'integer'],
            // Mobile money pushes a prompt to a handset, so it needs one.
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        if (PlatformGateways::needsPhone($data['gateway']) && blank($data['phone'] ?? null)) {
            return back()->with('error', 'Mobile money needs the phone number to send the prompt to.');
        }

        try {
            $payment = $this->checkout->start(
                $request->user(),
                array_values(array_unique($data['services'])),
                (int) $data['months'],
                $data['numberId'] ?? null,
                $data['gateway'],
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->sendToGateway($payment, $request->user(), $data['phone'] ?? null);
    }

    /**
     * Hand the invoice to the gateway and follow wherever it says.
     *
     * A gateway that fails here leaves a pending invoice reserving referral
     * credit, so it is abandoned rather than left hanging — otherwise a
     * reseller whose checkout broke quietly loses the credit it held.
     */
    private function sendToGateway(
        SubscriptionPayment $payment,
        Tenant $tenant,
        ?string $phone,
    ): RedirectResponse {
        $client = PlatformGateways::make($payment->gateway);

        if ($client === null) {
            $this->checkout->abandon($payment);

            return back()->with('error', 'That payment method is not available right now.');
        }

        // Charged in whatever the gateway settles in, which is not always what
        // we priced in — Snippe is Tanzanian mobile money and takes TZS. The
        // invoice row stays in USD so accounting has one currency.
        $initiation = $client->initiate(new PaymentRequest(
            reference: $payment->transaction_ref,
            amount: PlatformGateways::chargeAmount(
                $payment->gateway,
                Pricing::toCents((string) $payment->amount),
            ),
            currency: PlatformGateways::chargeCurrency($payment->gateway),
            webhookUrl: route('webhooks.billing', $payment->gateway),
            phone: (string) ($phone ?? ''),
            // Unlike a chat customer, a reseller has both — and Snippe
            // refuses an order that carries neither.
            customerName: (string) $tenant->business_name,
            customerEmail: (string) $tenant->email,
        ));

        if (! $initiation->started) {
            $this->checkout->abandon($payment);

            return back()->with('error', $initiation->message ?: 'The payment could not be started.');
        }

        // Mobile money has no page to visit — the prompt is already on the
        // reseller's handset, so telling them to check it is the whole step.
        if (blank($initiation->redirectUrl)) {
            return redirect()
                ->route('billing')
                ->with('success', 'Check your phone for the payment prompt, then come back here.');
        }

        return redirect()->away($initiation->redirectUrl);
    }
}
