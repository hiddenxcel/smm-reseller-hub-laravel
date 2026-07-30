<?php

namespace App\Services\Guarantee;

use App\Models\GuaranteeRule;
use Illuminate\Support\Collection;

/**
 * Decides, from a service name, whether a refill is allowed and for how many
 * days, using a tenant's guarantee rules.
 *
 * Precedence, ported verbatim from the old platform because resellers'
 * configured keywords depend on it:
 *   1. A NO-GUARANTEE keyword match always wins — refill blocked — even when a
 *      guarantee keyword also matches.
 *   2. Otherwise the guarantee keyword with the LONGEST match wins, so
 *      "365 days" beats a bare "5 days" substring.
 *   3. Nothing matched: blocked. Defaulting to "no guarantee" is the safe
 *      direction — it refuses a refill rather than granting one by accident.
 *
 * refill_days of 0 means lifetime.
 */
class GuaranteeMatcher
{
    /** @param Collection<int, GuaranteeRule> $rules */
    public function __construct(private Collection $rules) {}

    public static function forTenant(int $tenantId): self
    {
        return new self(
            GuaranteeRule::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->get()
        );
    }

    public function evaluate(string $serviceName): GuaranteeVerdict
    {
        $haystack = mb_strtolower($serviceName);

        foreach ($this->rules as $rule) {
            if ($rule->rule_type === 'no_guarantee' && $this->matches($haystack, $rule->keyword)) {
                return GuaranteeVerdict::blocked($rule->keyword);
            }
        }

        // Strictly-greater keeps the FIRST rule on a length tie, matching the
        // old implementation's behaviour.
        $best = null;
        foreach ($this->rules as $rule) {
            if ($rule->rule_type !== 'guarantee' || ! $this->matches($haystack, $rule->keyword)) {
                continue;
            }
            if ($best === null || mb_strlen($rule->keyword) > mb_strlen($best->keyword)) {
                $best = $rule;
            }
        }

        return $best !== null
            ? GuaranteeVerdict::allowed((int) $best->refill_days, $best->keyword)
            : GuaranteeVerdict::blocked();
    }

    private function matches(string $haystack, string $keyword): bool
    {
        $keyword = mb_strtolower(trim($keyword));

        return $keyword !== '' && str_contains($haystack, $keyword);
    }
}
