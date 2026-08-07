<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** Malformed JSON-LD is not an error anywhere — it is simply ignored. */
    public function test_the_structured_data_is_valid_json(): void
    {
        $body = $this->get('/')->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);

        $this->assertNotEmpty($matches, 'the page should carry a JSON-LD block');

        $decoded = json_decode(trim($matches[1]), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'JSON-LD must parse');
        $this->assertSame('SoftwareApplication', $decoded['@type']);
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
