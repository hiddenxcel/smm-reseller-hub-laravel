<?php

namespace App\Services\Customers;

use App\Jobs\SendCustomerBroadcast;
use App\Models\BotCustomer;
use App\Models\Tenant;
use Illuminate\Support\Facades\Bus;

/**
 * Runs one action across a selection of customers.
 *
 * Same two rules as the orders bulk runner: customers are re-fetched by id
 * scoped to the tenant, so a hand-edited id list cannot touch another
 * reseller's rows; and a customer the action does not fit is skipped with a
 * count, not treated as a failure.
 *
 * Broadcast is the one that needs a cap with teeth. Every recipient is a
 * WhatsApp API call, and Meta throttles hard — so the sends go out as queued
 * jobs, one per recipient, and the selection is limited.
 */
class BulkCustomerAction
{
    public const BLOCK = 'block';

    public const UNBLOCK = 'unblock';

    public const TAG = 'tag';

    public const UNTAG = 'untag';

    public const BROADCAST = 'broadcast';

    public const ALL = [self::BLOCK, self::UNBLOCK, self::TAG, self::UNTAG, self::BROADCAST];

    public const MAX_SELECTION = 500;

    /** Broadcasts fan out to one API call each, so they are capped harder. */
    public const MAX_BROADCAST = 250;

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public static function limitFor(string $action): int
    {
        return $action === self::BROADCAST ? self::MAX_BROADCAST : self::MAX_SELECTION;
    }

    /**
     * @param  list<int>  $ids
     * @return array{done: int, skipped: int, message: string}
     */
    public function run(string $action, array $ids, array $options = []): array
    {
        $customers = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('id', array_slice($ids, 0, self::limitFor($action)))
            ->get();

        return match ($action) {
            self::BLOCK => $this->setBlocked($customers, true),
            self::UNBLOCK => $this->setBlocked($customers, false),
            self::TAG => $this->changeTags($customers, (string) ($options['tag'] ?? ''), add: true),
            self::UNTAG => $this->changeTags($customers, (string) ($options['tag'] ?? ''), add: false),
            self::BROADCAST => $this->broadcast($customers, (string) ($options['text'] ?? '')),
        };
    }

    private function setBlocked($customers, bool $blocked): array
    {
        $done = 0;
        $skipped = 0;

        foreach ($customers as $customer) {
            if (($customer->blocked_at !== null) === $blocked) {
                $skipped++;

                continue;
            }

            $customer->forceFill(['blocked_at' => $blocked ? now() : null])->save();
            $done++;
        }

        $verb = $blocked ? 'blocked' : 'unblocked';

        return [
            'done' => $done,
            'skipped' => $skipped,
            'message' => $done === 0
                ? "Nothing to do — all {$skipped} were already {$verb}."
                : $this->describe($done, $skipped, $verb),
        ];
    }

    private function changeTags($customers, string $tag, bool $add): array
    {
        $tag = mb_substr(trim($tag), 0, 40);

        if ($tag === '') {
            return ['done' => 0, 'skipped' => 0, 'message' => 'Enter a tag first.'];
        }

        $done = 0;
        $skipped = 0;

        foreach ($customers as $customer) {
            $tags = collect($customer->tags ?? []);
            // Matched case-insensitively so "VIP" and "vip" are one tag, not two.
            $has = $tags->contains(fn ($existing) => mb_strtolower((string) $existing) === mb_strtolower($tag));

            if ($has === $add) {
                $skipped++;

                continue;
            }

            $next = $add
                ? $tags->push($tag)
                : $tags->reject(fn ($existing) => mb_strtolower((string) $existing) === mb_strtolower($tag));

            $customer->forceFill(['tags' => $next->take(20)->values()->all()])->save();
            $done++;
        }

        return [
            'done' => $done,
            'skipped' => $skipped,
            'message' => $this->describe($done, $skipped, $add ? "tagged “{$tag}”" : "untagged “{$tag}”"),
        ];
    }

    /**
     * Queue a message to each selected customer.
     *
     * The window is not checked here — it is checked inside each job, at the
     * moment of sending, because a long send outlives the check.
     */
    private function broadcast($customers, string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['done' => 0, 'skipped' => 0, 'message' => 'Write the message first.'];
        }

        $messaging = CustomerMessaging::for($this->tenant);

        if ($messaging->orderNumber() === null) {
            return [
                'done' => 0,
                'skipped' => 0,
                'message' => 'No WhatsApp number is connected to send from.',
            ];
        }

        $jobs = [];
        $skipped = 0;

        foreach ($customers as $customer) {
            if ($customer->blocked_at !== null) {
                $skipped++;

                continue;
            }

            $jobs[] = new SendCustomerBroadcast($this->tenant->id, $customer->id, $text);
        }

        if ($jobs !== []) {
            Bus::batch($jobs)->name("broadcast:{$this->tenant->id}")->dispatch();
        }

        $count = count($jobs);

        return [
            'done' => $count,
            'skipped' => $skipped,
            'message' => $count === 0
                ? 'Nothing to send — every selected customer is blocked.'
                : "Sending to {$count} ".str('customer')->plural($count)
                    .($skipped > 0 ? ", {$skipped} blocked and skipped" : '')
                    .'. Anyone outside WhatsApp’s 24-hour window will be skipped.',
        ];
    }

    private function describe(int $done, int $skipped, string $verb): string
    {
        $parts = [$done.' '.str('customer')->plural($done)." {$verb}"];

        if ($skipped > 0) {
            $parts[] = "{$skipped} skipped";
        }

        return implode(', ', $parts).'.';
    }
}
