<?php

namespace Tests\Unit;

use App\Models\GuaranteeRule;
use App\Services\Guarantee\GuaranteeMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Pure logic, no database — rules are built in memory.
 *
 * This decides whether a customer gets a refill, so the precedence rules are
 * worth pinning exactly: no-guarantee wins outright, longest guarantee
 * keyword wins otherwise, and no match means blocked.
 */
class GuaranteeMatcherTest extends TestCase
{
    /** @param array<int, array{type: string, keyword: string, days?: int}> $specs */
    private function matcher(array $specs): GuaranteeMatcher
    {
        $rules = new Collection(array_map(
            fn (array $spec) => new GuaranteeRule([
                'rule_type' => $spec['type'],
                'keyword' => $spec['keyword'],
                'refill_days' => $spec['days'] ?? 30,
            ]),
            $specs,
        ));

        return new GuaranteeMatcher($rules);
    }

    public function test_a_guarantee_keyword_allows_a_refill(): void
    {
        $verdict = $this->matcher([
            ['type' => 'guarantee', 'keyword' => '30 days', 'days' => 30],
        ])->evaluate('Instagram Followers | 30 Days Refill');

        $this->assertTrue($verdict->allowed);
        $this->assertSame(30, $verdict->days);
        $this->assertFalse($verdict->lifetime);
        $this->assertSame('30 days', $verdict->matchedKeyword);
    }

    public function test_no_guarantee_beats_a_matching_guarantee_keyword(): void
    {
        // Both keywords are present in the name; "no refill" must win.
        $verdict = $this->matcher([
            ['type' => 'guarantee', 'keyword' => '30 days', 'days' => 30],
            ['type' => 'no_guarantee', 'keyword' => 'no refill'],
        ])->evaluate('TikTok Views | 30 Days | No Refill');

        $this->assertFalse($verdict->allowed);
        $this->assertNull($verdict->days);
        $this->assertSame('no refill', $verdict->matchedKeyword);
    }

    public function test_no_guarantee_wins_regardless_of_rule_order(): void
    {
        // Same as above but with the no-guarantee rule listed first.
        $verdict = $this->matcher([
            ['type' => 'no_guarantee', 'keyword' => 'no refill'],
            ['type' => 'guarantee', 'keyword' => '30 days', 'days' => 30],
        ])->evaluate('TikTok Views | 30 Days | No Refill');

        $this->assertFalse($verdict->allowed);
    }

    public function test_the_longest_matching_guarantee_keyword_wins(): void
    {
        // "365 days" contains "5 days" as a substring; the longer rule must win.
        $verdict = $this->matcher([
            ['type' => 'guarantee', 'keyword' => '5 days', 'days' => 5],
            ['type' => 'guarantee', 'keyword' => '365 days', 'days' => 365],
        ])->evaluate('YouTube Views | 365 Days Guarantee');

        $this->assertTrue($verdict->allowed);
        $this->assertSame(365, $verdict->days);
        $this->assertSame('365 days', $verdict->matchedKeyword);
    }

    public function test_longest_wins_regardless_of_rule_order(): void
    {
        $verdict = $this->matcher([
            ['type' => 'guarantee', 'keyword' => '365 days', 'days' => 365],
            ['type' => 'guarantee', 'keyword' => '5 days', 'days' => 5],
        ])->evaluate('YouTube Views | 365 Days Guarantee');

        $this->assertSame(365, $verdict->days);
    }

    public function test_zero_refill_days_means_lifetime(): void
    {
        $verdict = $this->matcher([
            ['type' => 'guarantee', 'keyword' => 'lifetime', 'days' => 0],
        ])->evaluate('Instagram Likes | Lifetime Guarantee');

        $this->assertTrue($verdict->allowed);
        $this->assertTrue($verdict->lifetime);
        $this->assertNull($verdict->days, 'lifetime carries no day count');
    }

    public function test_nothing_matching_is_blocked_not_allowed(): void
    {
        // Defaulting to blocked is the safe direction: it refuses a refill
        // rather than granting one the reseller never configured.
        $verdict = $this->matcher([
            ['type' => 'guarantee', 'keyword' => '30 days', 'days' => 30],
        ])->evaluate('Facebook Page Likes');

        $this->assertFalse($verdict->allowed);
        $this->assertNull($verdict->matchedKeyword);
    }

    public function test_no_rules_at_all_is_blocked(): void
    {
        $this->assertFalse($this->matcher([])->evaluate('Anything At All')->allowed);
    }

    public function test_matching_is_case_insensitive(): void
    {
        $verdict = $this->matcher([
            ['type' => 'guarantee', 'keyword' => 'REFILL', 'days' => 30],
        ])->evaluate('instagram followers refill');

        $this->assertTrue($verdict->allowed);
    }

    public function test_keyword_whitespace_is_trimmed(): void
    {
        $verdict = $this->matcher([
            ['type' => 'guarantee', 'keyword' => '  30 days  ', 'days' => 30],
        ])->evaluate('Service with 30 Days');

        $this->assertTrue($verdict->allowed);
    }

    public function test_an_empty_keyword_never_matches(): void
    {
        // Without the guard an empty keyword would match every service name,
        // silently granting refills on everything.
        $verdict = $this->matcher([
            ['type' => 'guarantee', 'keyword' => '', 'days' => 30],
        ])->evaluate('Any Service');

        $this->assertFalse($verdict->allowed);
    }

    public function test_an_empty_no_guarantee_keyword_does_not_block_everything(): void
    {
        $verdict = $this->matcher([
            ['type' => 'no_guarantee', 'keyword' => '   '],
            ['type' => 'guarantee', 'keyword' => '30 days', 'days' => 30],
        ])->evaluate('Service with 30 Days');

        $this->assertTrue($verdict->allowed);
    }
}
