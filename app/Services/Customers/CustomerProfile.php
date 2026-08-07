<?php

namespace App\Services\Customers;

use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Models\Ticket;
use App\Services\Orders\OrderStatus;
use Illuminate\Support\Carbon;

/**
 * Everything the slide-over shows about one customer.
 *
 * Each tab is its own method and each is loaded on demand: opening the panel
 * fetches the overview, and the other five arrive only if the reseller clicks
 * them. Loading all six up front would mean six queries per row click, most of
 * them never read.
 *
 * Tickets and messages are keyed on the phone number, not customer_id —
 * `tickets.customer_identifier` and `bot_messages.customer_phone` are how those
 * tables identify a person, and a customer row may not have existed when they
 * first wrote in.
 */
class CustomerProfile
{
    public function __construct(private BotCustomer $customer) {}

    public static function for(BotCustomer $customer): self
    {
        return new self($customer);
    }

    /** The tab a reseller lands on. */
    public function overview(): array
    {
        $orders = BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->customer->tenant_id)
            ->where('customer_id', $this->customer->id)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status ilike '%complet%') as completed")
            ->selectRaw("count(*) filter (where status ilike '%cancel%' or status ilike '%refund%' or status ilike '%partial%') as cancelled")
            ->first();

        $orderCount = (int) ($orders->total ?? 0);

        // What they actually pay with, taken from their successful payments
        // rather than guessed — `last_payment_phone` only records a mobile
        // money number, which is not the same question.
        $preferredGateway = BotPayment::withoutTenantScope()
            ->where('tenant_id', $this->customer->tenant_id)
            ->where('customer_id', $this->customer->id)
            ->where('status', 'success')
            ->selectRaw('gateway, count(*) as uses')
            ->groupBy('gateway')
            ->orderByDesc('uses')
            ->value('gateway');

        return [
            'id' => $this->customer->id,
            'name' => $this->customer->name,
            'phone' => $this->customer->phone,
            'email' => $this->customer->email,
            'country' => $this->customer->country,
            'lang' => $this->customer->lang,
            'notes' => $this->customer->notes,
            'tags' => $this->customer->tags ?? [],
            'blocked' => $this->customer->blocked_at !== null,
            'blockedAt' => $this->customer->blocked_at?->toIso8601String(),
            'joinedAt' => $this->customer->created_at?->toIso8601String(),
            'lastSeenAt' => $this->customer->last_seen_at?->toIso8601String(),

            'orders' => [
                'total' => $orderCount,
                'completed' => (int) ($orders->completed ?? 0),
                'cancelled' => (int) ($orders->cancelled ?? 0),
            ],

            'spent' => (float) $this->customer->total_spent,
            'balance' => (float) $this->customer->balance,
            'preferredPayment' => $preferredGateway,
            'lastPaymentPhone' => $this->customer->last_payment_phone,

            'referral' => [
                'code' => $this->customer->referral_code,
                'earnings' => (float) $this->customer->referral_earnings,
                'invited' => CustomerReferrals::countFor($this->customer),
                'invitedBy' => $this->customer->referrer?->only(['id', 'name', 'phone']),
            ],

            'segments' => CustomerSegment::for($this->customer, $orderCount),
        ];
    }

    public function orders(int $limit = 25): array
    {
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->customer->tenant_id)
            ->where('customer_id', $this->customer->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BotOrder $order) => [
                'id' => $order->id,
                'service' => $order->service_name,
                'quantity' => $order->quantity,
                'amount' => $order->amount !== null ? (float) $order->amount : null,
                'charge' => $order->charge !== null ? (float) $order->charge : null,
                // What the reseller kept. Null rather than zero when the panel
                // has not reported a charge — an unknown margin shown as 0.00
                // reads as "made nothing", which is a different claim.
                'profit' => $order->amount !== null && $order->charge !== null
                    ? round((float) $order->amount - (float) $order->charge, 2)
                    : null,
                'status' => OrderStatus::fold($order->status),
                'rawStatus' => $order->status,
                'at' => $order->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The conversation, both bots interleaved and oldest first — read as a
     * chat, not as a log.
     */
    public function messages(int $limit = 60): array
    {
        return BotMessage::withoutTenantScope()
            ->where('tenant_id', $this->customer->tenant_id)
            ->where('customer_phone', $this->customer->phone)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (BotMessage $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'text' => $message->message,
                'bot' => $message->bot_type,
                'templateKey' => $message->template_key,
                'at' => $message->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Wallet balance plus everything that moved it. */
    public function wallet(int $limit = 30): array
    {
        $payments = BotPayment::withoutTenantScope()
            ->where('tenant_id', $this->customer->tenant_id)
            ->where('customer_id', $this->customer->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BotPayment $payment) => [
                'id' => $payment->id,
                'type' => $payment->type,
                'gateway' => $payment->gateway,
                'reference' => $payment->transaction_ref,
                'amount' => (float) $payment->amount,
                'status' => $payment->status,
                'at' => $payment->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'balance' => (float) $this->customer->balance,
            'spent' => (float) $this->customer->total_spent,
            'referralEarnings' => (float) $this->customer->referral_earnings,
            'transactions' => $payments,
        ];
    }

    public function tickets(int $limit = 25): array
    {
        return Ticket::withoutTenantScope()
            ->where('tenant_id', $this->customer->tenant_id)
            ->where('customer_identifier', $this->customer->phone)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'category' => $ticket->category,
                'subcategory' => $ticket->subcategory,
                'orderRef' => $ticket->order_ref,
                'at' => $ticket->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * One timeline, merged from what actually happened.
     *
     * Assembled from the existing tables rather than an events table: there is
     * no activity log for customers, and inventing one would mean the timeline
     * started empty for every customer who already exists.
     */
    public function activity(int $limit = 40): array
    {
        $events = collect();

        $events->push([
            'type' => 'joined',
            'at' => $this->customer->created_at?->toIso8601String(),
            'title' => 'First message',
            'detail' => null,
        ]);

        BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->customer->tenant_id)
            ->where('customer_id', $this->customer->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(fn (BotOrder $order) => $events->push([
                'type' => 'order',
                'at' => $order->created_at?->toIso8601String(),
                'title' => 'Placed an order',
                'detail' => trim(($order->service_name ?? 'Order').' · #'.$order->id),
                'amount' => $order->amount !== null ? (float) $order->amount : null,
                'status' => OrderStatus::fold($order->status),
            ]));

        BotPayment::withoutTenantScope()
            ->where('tenant_id', $this->customer->tenant_id)
            ->where('customer_id', $this->customer->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(fn (BotPayment $payment) => $events->push([
                'type' => $payment->status === 'success' ? 'payment' : 'payment_pending',
                'at' => $payment->created_at?->toIso8601String(),
                'title' => $payment->status === 'success'
                    ? 'Payment received'
                    : 'Payment '.$payment->status,
                'detail' => $payment->gateway,
                'amount' => (float) $payment->amount,
            ]));

        Ticket::withoutTenantScope()
            ->where('tenant_id', $this->customer->tenant_id)
            ->where('customer_identifier', $this->customer->phone)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(fn (Ticket $ticket) => $events->push([
                'type' => 'ticket',
                'at' => $ticket->created_at?->toIso8601String(),
                'title' => 'Opened a ticket',
                'detail' => $ticket->subject ?? $ticket->subcategory,
                'status' => $ticket->status,
            ]));

        if ($this->customer->blocked_at !== null) {
            $events->push([
                'type' => 'blocked',
                'at' => $this->customer->blocked_at->toIso8601String(),
                'title' => 'Blocked',
                'detail' => 'The bot stopped answering this number',
            ]);
        }

        return $events
            ->filter(fn (array $event) => $event['at'] !== null)
            ->sortByDesc(fn (array $event) => Carbon::parse($event['at'])->timestamp)
            ->take($limit)
            ->values()
            ->all();
    }
}
