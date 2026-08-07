<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The headers a browser is sent on every page.
 *
 * Worth asserting because nothing visible breaks when they go missing. A
 * removed X-Frame-Options looks exactly like a working site right up until
 * someone frames the login page, and a middleware dropped from the stack
 * during an unrelated refactor is silent.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_refuses_to_be_framed(): void
    {
        $this->get('/')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_every_page_stops_content_type_sniffing(): void
    {
        $this->get('/')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * A reseller following a link out should not carry the customer or order
     * they were looking at into someone else's logs.
     */
    public function test_every_page_limits_what_the_referrer_leaks(): void
    {
        $this->get('/')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_every_page_denies_hardware_this_app_never_uses(): void
    {
        $policy = $this->get('/')->headers->get('Permissions-Policy');

        foreach (['camera=()', 'microphone=()', 'geolocation=()'] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
    }

    /** The login page is the one most worth framing, so it must be covered too. */
    public function test_the_login_page_carries_them_as_well(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    /**
     * HSTS over plain http would pin the browser to https for localhost and
     * break every other project on the developer's machine.
     */
    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        $this->assertNull($this->get('/')->headers->get('Strict-Transport-Security'));
    }
}
