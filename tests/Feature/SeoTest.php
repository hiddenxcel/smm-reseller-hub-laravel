<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response;
use Tests\TestCase;

/**
 * What a crawler sees.
 *
 * These are cheap tests for things that fail silently and expensively: a
 * sitemap that 500s is never fetched again for days, a canonical that carries
 * a tracking parameter splits one page's ranking across every link anyone has
 * ever shared, and a malformed schema block is simply ignored.
 *
 * None of it is visible in a browser, which is exactly why it needs testing.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    // ---- the sitemap -----------------------------------------------------

    public function test_the_sitemap_is_valid_xml(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');

        // Parsed rather than string-matched: a sitemap that looks right and
        // does not parse is one Google discards without telling anyone.
        $xml = simplexml_load_string($response->getContent());

        $this->assertNotFalse($xml, 'the sitemap must parse as XML');
        $this->assertGreaterThan(0, $xml->count());
    }

    /**
     * The declaration has to be the first byte. A stray newline ahead of it —
     * the easiest thing in the world to leave in a Blade template — makes the
     * whole document invalid.
     */
    public function test_the_sitemap_starts_with_its_declaration(): void
    {
        $body = $this->get('/sitemap.xml')->getContent();

        $this->assertStringStartsWith('<?xml', $body);
    }

    public function test_the_sitemap_lists_the_public_pages(): void
    {
        $body = $this->get('/sitemap.xml')->getContent();

        foreach (['home', 'features', 'pricing', 'blog', 'contact'] as $name) {
            $this->assertStringContainsString(route($name), $body, "{$name} should be listed");
        }
    }

    public function test_a_published_post_is_listed(): void
    {
        $post = BlogPost::create([
            'title' => 'How resellers price their services',
            'slug' => 'how-resellers-price',
            'excerpt' => 'A short guide.',
            'body' => 'The body.',
            'published_at' => now()->subDay(),
        ]);

        $body = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString(route('blog.show', $post->slug), $body);
    }

    /** A draft is not public, and pointing a crawler at one publishes it. */
    public function test_a_draft_post_is_not_listed(): void
    {
        BlogPost::create([
            'title' => 'Not finished yet',
            'slug' => 'not-finished-yet',
            'excerpt' => 'Draft.',
            'body' => 'The body.',
            'published_at' => null,
        ]);

        $body = $this->get('/sitemap.xml')->getContent();

        $this->assertStringNotContainsString('not-finished-yet', $body);
    }

    /**
     * Nothing behind a login belongs here. Listing it wastes a crawler's
     * budget on pages it will only be redirected away from, and advertises
     * the shape of the console.
     */
    public function test_the_sitemap_lists_nothing_private(): void
    {
        $body = $this->get('/sitemap.xml')->getContent();

        foreach (['/hx-control', '/dashboard', '/onboarding', '/billing', '/settings'] as $path) {
            $this->assertStringNotContainsString("{$path}<", $body, "{$path} must not be listed");
        }
    }

    // ---- the tags every page carries -------------------------------------

    public function test_the_landing_page_carries_a_description_and_a_canonical(): void
    {
        $body = $this->get('/')->getContent();

        // Matched on the attribute rather than the whole opening tag: the
        // Blade tags also carry `inertia="…"` first, and asserting on the
        // exact spelling would break the day an attribute is reordered
        // without anything actually being wrong.
        $this->assertMatchesRegularExpression('#<meta[^>]+name="description"#', $body);
        $this->assertMatchesRegularExpression('#<link[^>]+rel="canonical"#', $body);
        $this->assertMatchesRegularExpression('#<meta[^>]+property="og:image"#', $body);
        $this->assertMatchesRegularExpression('#<meta[^>]+name="twitter:card"#', $body);
    }

    /**
     * The one that costs real ranking. ?ref= and ?utm_source= are the same
     * page — a canonical that echoes them back tells Google there are as many
     * copies of the home page as there are links anyone has ever shared.
     */
    public function test_the_canonical_drops_the_query_string(): void
    {
        $body = $this->get('/?utm_source=twitter&ref=someone')->getContent();

        preg_match('#<link rel="canonical" href="([^"]+)"#', $body, $matches);

        $this->assertNotEmpty($matches, 'the page should carry a canonical');

        // Only the canonical is asserted on. Inertia echoes the full request
        // URL into its own data-page payload, which is not something a
        // crawler reads — asserting the parameter is absent from the whole
        // document would fail on that and prove nothing about ranking.
        $this->assertSame(url('/'), $matches[1]);
    }

    /**
     * Exactly one description per page.
     *
     * Inertia only replaces a Blade tag when both sides are keyed — `inertia`
     * on the Blade tag, `head-key` on the React one. Miss either and the page
     * carries two, with nothing deciding which a crawler believes. It is
     * invisible in a browser, which is why it needs asserting.
     */
    public function test_a_page_carries_exactly_one_description(): void
    {
        foreach (['/', '/pricing', '/features', '/blog'] as $path) {
            $body = $this->get($path)->getContent();

            $this->assertSame(
                1,
                preg_match_all('#<meta[^>]+name="description"#', $body),
                "{$path} should carry exactly one description",
            );
        }
    }

    /**
     * The same rule as above, on the path that made it fragile.
     *
     * With SSR on, the page's own tags are printed into the document by the
     * inertiaHead directive rather than swapped in by the browser, so the
     * Blade defaults would be a second title and a second description sitting
     * above them. Inertia's keying does not help — that runs in the browser,
     * and a crawler reads the bytes as served, taking the first title it meets.
     *
     * Faked rather than run against Node: booting the renderer in a test would
     * make this suite depend on a built bundle. What is being asserted is that
     * the Blade template steps aside when something else is providing the
     * head, which is decided by the gateway's answer either way.
     */
    public function test_a_server_rendered_page_carries_no_duplicate_head_tags(): void
    {
        $this->app->bind(Gateway::class, fn () => new class implements Gateway
        {
            public function dispatch(array $page): ?Response
            {
                return new Response(
                    head: '<title inertia>A Page - Resellers Hub</title>'
                        .'<meta name="description" content="The page\'s own." inertia="description">',
                    body: '<div id="app"><h1>A Page</h1></div>',
                );
            }
        });

        $body = $this->get('/')->getContent();

        $this->assertSame(
            1,
            preg_match_all('#<title#', $body),
            'a server-rendered page should carry exactly one title',
        );

        $this->assertSame(
            1,
            preg_match_all('#<meta[^>]+name="description"#', $body),
            'a server-rendered page should carry exactly one description',
        );

        // The page's own, not the generic default it would otherwise sit under.
        $this->assertStringContainsString('A Page - Resellers Hub', $body);
    }

    /** Malformed JSON-LD is not an error anywhere — it is simply ignored. */
    public function test_the_structured_data_is_valid_json(): void
    {
        $body = $this->get('/')->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);

        $this->assertNotEmpty($matches, 'the page should carry a JSON-LD block');

        $decoded = json_decode(trim($matches[1]), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'JSON-LD must parse');

        $types = array_column($decoded['@graph'], '@type');

        $this->assertContains('Organization', $types);
        $this->assertContains('SoftwareApplication', $types);
    }

    /**
     * The advertised price has to be one a visitor can actually pay.
     *
     * Google drops a rich result whose offer does not match the page, and
     * "from $17" above a $5 service is the kind of mismatch that earns a
     * manual action rather than a ranking.
     */
    public function test_the_advertised_price_is_the_cheapest_real_one(): void
    {
        // Seeded here rather than relied on: RefreshDatabase leaves no plans,
        // and a test that passes against an empty table is asserting that two
        // zeroes match rather than that the price is right.
        $this->seed(\Database\Seeders\PlanSeeder::class);

        // The view caches the lookup for an hour, which would otherwise carry
        // a value across from whatever ran before this.
        cache()->forget('seo:cheapest-plan');

        $cheapest = Plan::where('status', 'active')->min('price_monthly');

        $this->assertGreaterThan(0, $cheapest, 'the fixture should have priced plans');

        $body = $this->get('/')->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);

        $decoded = json_decode(trim($matches[1]), true);

        $offer = collect($decoded['@graph'])
            ->firstWhere('@type', 'SoftwareApplication')['offers'] ?? null;

        $this->assertNotNull($offer, 'the software entry should carry an offer');
        $this->assertSame(
            number_format((float) $cheapest, 2, '.', ''),
            $offer['price'],
            'the schema price should be the cheapest active plan',
        );
    }

    /**
     * Search Console verification, which is quiet when it breaks.
     *
     * Losing the tag un-verifies the property: no index coverage, no queries
     * report, and no notice — the site carries on serving perfectly while the
     * one place that would tell us about a crawl problem goes dark. Asserted
     * on more than one path because it has to survive on every page, not only
     * the home page Google happens to check first.
     */
    public function test_every_page_carries_the_search_console_tag(): void
    {
        foreach (['/', '/pricing', '/blog'] as $path) {
            $this->assertMatchesRegularExpression(
                '#<meta[^>]+name="google-site-verification"[^>]+content="[^"]+"#',
                $this->get($path)->getContent(),
                "{$path} should carry the verification tag",
            );
        }
    }

    // ---- robots ----------------------------------------------------------

    public function test_robots_points_at_the_sitemap_and_hides_the_console(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Sitemap:', $robots);
        $this->assertStringContainsString('/sitemap.xml', $robots);
        $this->assertStringContainsString('Disallow: /hx-control', $robots);
    }
}
