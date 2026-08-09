<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @php
            /*
             * Whether Node is rendering this page, decided before anything in
             * the head is written.
             *
             * @inertiaHead further down prints the page's own <title> and
             * <meta name="description">. The defaults below print the same two
             * tags. With SSR on, both end up in the document: Inertia dedupes
             * by the `inertia` attribute once React takes over in the browser,
             * but a crawler reads the HTML as served and takes the first title
             * it meets — the generic one, on every page.
             *
             * Dispatching here rather than reading a flag because there is no
             * flag to read: the directives share $__inertiaSsrResponse, and
             * whichever runs first is the one that calls Node. @inertia is in
             * the body, so without this the head would be written before the
             * answer was known. Guarded the same way the directives guard it,
             * so this stays one render per request rather than two.
             */
            if (! isset($__inertiaSsrDispatched)) {
                $__inertiaSsrDispatched = true;
                $__inertiaSsrResponse = app(\Inertia\Ssr\Gateway::class)->dispatch($page);
            }

            $ssr = (bool) $__inertiaSsrResponse;
        @endphp

        @unless ($ssr)
            <title inertia>{{ config('app.name', 'Laravel') }}</title>
        @endunless

        {{-- Icons. All generated from one source by `make_icons.py`, so the tab
             icon, the phone home screen and the in-app mark stay in step. --}}
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="theme-color" content="#0EA472">

        {{-- Search and social.

             Defaults live here rather than on every page: a page that forgets
             to set a description still gets a usable one, and the shape can
             only be wrong in one place. Inertia's <Head> overrides any of
             these per page — the tags it emits come after these and win.

             The canonical is built from the current path with the query string
             dropped: ?ref=x and ?utm_source=y are the same page, and letting
             each be indexed separately splits the ranking between them. --}}
        @php
            $seoTitle = config('app.name').' — WhatsApp Bots for SMM Panels';
            $seoDescription = 'Sell followers, likes and views on WhatsApp around the clock. Your customers order, pay by mobile money or crypto, and get support automatically — on top of the SMM panel you already run.';
            $seoImage = config('app.url').'/logo.png';
            $canonical = url()->current();

            // Built here rather than inline in the script tag: Blade's @json
            // directive cannot parse a nested array written across lines, and
            // fails the whole view — every page, not just this tag.
            //
            // Two things rather than one, in a @graph: the software being sold,
            // and the company selling it. They are linked by @id, so a crawler
            // reads one organisation with a product rather than two unrelated
            // records that happen to share a domain.
            $seoSchema = json_encode([
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'Organization',
                        '@id' => config('app.url').'/#organization',
                        'name' => config('app.name'),
                        'url' => config('app.url'),
                        'logo' => $seoImage,
                        'description' => $seoDescription,
                        'email' => config('mail.contact_address', 'info@smmresellershub.com'),
                        // Where the customers are, not where a server is. This
                        // is the honest answer to "who is this for", and the
                        // three markets have different payment rails behind
                        // them — see the blog.
                        'areaServed' => ['KE', 'TZ', 'UG', 'NG', 'GH', 'ZA', 'IN', 'PK'],
                    ],
                    [
                        '@type' => 'SoftwareApplication',
                        'name' => config('app.name'),
                        'applicationCategory' => 'BusinessApplication',
                        'operatingSystem' => 'Web',
                        'description' => $seoDescription,
                        'url' => config('app.url'),
                        'publisher' => ['@id' => config('app.url').'/#organization'],
                        // The cheapest service, read from the plans rather than
                        // typed here: "from $17" above a $5 service is the kind
                        // of mismatch that gets a rich result dropped, and a
                        // hand-written number goes stale the first time pricing
                        // changes. Cached, so this is not a query per request.
                        'offers' => [
                            '@type' => 'Offer',
                            'price' => number_format(
                                (float) cache()->remember(
                                    'seo:cheapest-plan',
                                    now()->addHour(),
                                    fn () => \App\Models\Plan::where('status', 'active')->min('price_monthly') ?? 5.00,
                                ),
                                2, '.', ''
                            ),
                            'priceCurrency' => 'USD',
                            'availability' => 'https://schema.org/InStock',
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @endphp

        {{-- `inertia` on each tag is what lets a page replace it rather than
             add a second one. Without it a page setting its own description
             leaves both in the document, and a crawler is free to read the
             wrong one. --}}
        {{-- The keyed tags are the ones a page replaces, so under SSR they are
             left to @inertiaHead. The unkeyed ones below are the same on every
             page and are written here either way. --}}
        @unless ($ssr)
            <meta inertia="description" name="description" content="{{ $seoDescription }}">
            <meta inertia="og:title" property="og:title" content="{{ $seoTitle }}">
            <meta inertia="og:description" property="og:description" content="{{ $seoDescription }}">
            <meta inertia="og:image" property="og:image" content="{{ $seoImage }}">
            <meta inertia="twitter:title" name="twitter:title" content="{{ $seoTitle }}">
            <meta inertia="twitter:description" name="twitter:description" content="{{ $seoDescription }}">
            <meta inertia="twitter:image" name="twitter:image" content="{{ $seoImage }}">
        @endunless

        <link rel="canonical" href="{{ $canonical }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:url" content="{{ $canonical }}">

        <meta name="twitter:card" content="summary_large_image">

        {{-- Tells Google what kind of thing this is, which is what earns the
             richer result rather than a plain blue link. --}}
        <script type="application/ld+json">{!! $seoSchema !!}</script>

        {{-- Theme, applied before first paint.

             Inline and blocking on purpose: doing this from React means the
             page renders light for a frame and then snaps to dark, which is
             worse than a few bytes here.

             Light until the reseller chooses otherwise, rather than following
             the OS. Most phones now default to dark, so deferring to that
             setting meant most first-time visitors met a dark marketing site —
             and the brand, the screenshots and the phone demo were all built
             against the light canvas. A reseller who prefers dark still gets
             it the moment they pick it; the choice is remembered. --}}
        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('theme');
                    var dark = stored === 'dark';
                    document.documentElement.classList.toggle('dark', dark);
                    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
                } catch (e) {
                    /* private mode with storage disabled — the OS default stands */
                }
            })();
        </script>

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
