<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server side rendering
    |--------------------------------------------------------------------------
    |
    | PHP hands the page to a small Node process, which renders the React tree
    | to HTML and hands it back, so the document that leaves nginx already has
    | its words in it. Crawlers that do not run JavaScript — Bing, Yandex, the
    | link unfurlers behind WhatsApp and Telegram — see the page rather than an
    | empty div.
    |
    | Disabled by default so a developer without the Node process running still
    | gets a working site rather than a failed render. Production turns it on
    | in .env; see deploy/ for the supervisor program that keeps it alive.
    |
    */

    'ssr' => [

        'enabled' => env('INERTIA_SSR_ENABLED', false),

        // Loopback on purpose: this port must never be reachable from outside
        // the box. It answers unauthenticated render requests, and the render
        // it returns is inserted into the page as trusted HTML.
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
    ],

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/Pages')],
        'page_extensions' => ['tsx'],
    ],

];
