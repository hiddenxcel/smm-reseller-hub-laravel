<?php

namespace App\Services\Guarantee;

use App\Models\BotOrder;
use App\Models\BotService;
use App\Services\Bots\BotSettings;
use App\Services\Catalogue\ServiceFeatures;
use Illuminate\Support\Arr;

/**
 * Decides whether a refill is owed, in this order:
 *
 *   1. The reseller's own rules. If one matches, it is the answer — rules are
 *      how a reseller overrides what a service name says.
 *   2. What the service itself promises: the reseller's refill wording on the
 *      service, then the name ("365 Days Refill", "R30", "No Refill").
 *   3. The default for services that say nothing: refuse, allow, or ask a
 *      person. Refusing is the default because a refill granted by accident
 *      costs money and a refusal costs a message.
 *
 * Where the order is one of ours, its age is checked against the days
 * promised. An order the panel placed on its own has no date we can see, so
 * the panel is left to decide that part.
 */
class RefillPolicy
{
    public const DEFAULT_REFUSE = 'refuse';

    public const DEFAULT_ALLOW = 'allow';

    public const DEFAULT_HUMAN = 'human';

    public const DEFAULTS = [self::DEFAULT_REFUSE, self::DEFAULT_ALLOW, self::DEFAULT_HUMAN];

    public function __construct(
        private GuaranteeMatcher $rules,
        private bool $autoRead,
        private string $default,
        private string $unknownOrder = self::DEFAULT_ALLOW,
    ) {}

    public static function forTenant(int $tenantId): self
    {
        $settings = BotSettings::for($tenantId, 'support');
        $default = (string) Arr::get($settings, 'refill.default', self::DEFAULT_REFUSE);
        $unknown = (string) Arr::get($settings, 'refill.unknown_order', self::DEFAULT_ALLOW);

        return new self(
            GuaranteeMatcher::forTenant($tenantId),
            (bool) Arr::get($settings, 'refill.auto_read', true),
            in_array($default, self::DEFAULTS, true) ? $default : self::DEFAULT_REFUSE,
            in_array($unknown, self::DEFAULTS, true) ? $unknown : self::DEFAULT_ALLOW,
        );
    }

    public function decide(?BotOrder $order): RefillDecision
    {
        // An order the bot never placed has no service we can see: no name to
        // match a rule against and no promise to read, so the only honest
        // answers are to let the panel decide, to refuse, or to ask a person.
        if ($order === null) {
            return $this->answer($this->unknownOrder, 'panel');
        }

        $name = (string) ($order->service_name ?? '');

        $verdict = $this->rules->evaluate($name);

        // A rule matched — for or against.
        if ($verdict->matchedKeyword !== null) {
            return $verdict->allowed
                ? $this->checkAge($order, $verdict->days, $verdict->lifetime, 'rule')
                : RefillDecision::refuse('rule');
        }

        if ($this->autoRead) {
            [$promise, $source] = $this->promiseOf($order, $name);

            if ($promise === 'No refill') {
                return RefillDecision::refuse($source);
            }

            if ($promise === 'Lifetime') {
                return RefillDecision::allow(null, true, $source);
            }

            if ($promise !== null && preg_match('/^(\d+) days$/', $promise, $m) === 1) {
                return $this->checkAge($order, (int) $m[1], false, $source);
            }
        }

        return $this->answer($this->default, 'default');
    }

    private function answer(string $policy, string $source): RefillDecision
    {
        return match ($policy) {
            self::DEFAULT_ALLOW => RefillDecision::allow(null, false, $source),
            self::DEFAULT_HUMAN => RefillDecision::human(),
            default => RefillDecision::refuse($source),
        };
    }

    /**
     * What the service says about refill: the reseller's wording first, since
     * it is deliberate, then the name the panel gave it.
     *
     * @return array{0: ?string, 1: string}
     */
    private function promiseOf(?BotOrder $order, string $name): array
    {
        if ($order !== null && $order->service_id !== null) {
            $wording = BotService::withoutTenantScope()
                ->where('tenant_id', $order->tenant_id)
                ->whereKey($order->service_id)
                ->value('refill_info');

            $fromWording = $wording ? ServiceFeatures::readRefill((string) $wording) : null;

            if ($fromWording !== null) {
                return [$fromWording, 'service'];
            }
        }

        return [ServiceFeatures::readRefill($name), 'name'];
    }

    private function checkAge(?BotOrder $order, ?int $days, bool $lifetime, string $source): RefillDecision
    {
        if ($lifetime || $days === null || $days === 0 || $order?->created_at === null) {
            return RefillDecision::allow($days === 0 ? null : $days, $lifetime || $days === 0, $source);
        }

        $age = (int) $order->created_at->diffInDays(now());

        return $age > $days
            ? RefillDecision::expired($days, $age, $source)
            : RefillDecision::allow($days, false, $source);
    }
}
