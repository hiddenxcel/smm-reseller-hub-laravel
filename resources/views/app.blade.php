<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

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
            $seoSchema = json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                'name' => config('app.name'),
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'description' => $seoDescription,
                'url' => config('app.url'),
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '17.00',
                    'priceCurrency' => 'USD',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @endphp

        {{-- `inertia` on each tag is what lets a page replace it rather than
             add a second one. Without it a page setting its own description
             leaves both in the document, and a crawler is free to read the
             wrong one. --}}
        <meta inertia="description" name="description" content="{{ $seoDescription }}">
        <link rel="canonical" href="{{ $canonical }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta inertia="og:title" property="og:title" content="{{ $seoTitle }}">
        <meta inertia="og:description" property="og:description" content="{{ $seoDescription }}">
        <meta inertia="og:image" property="og:image" content="{{ $seoImage }}">
        <meta property="og:url" content="{{ $canonical }}">

        <meta name="twitter:card" content="summary_large_image">
        <meta inertia="twitter:title" name="twitter:title" content="{{ $seoTitle }}">
        <meta inertia="twitter:description" name="twitter:description" content="{{ $seoDescription }}">
        <meta inertia="twitter:image" name="twitter:image" content="{{ $seoImage }}">

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
