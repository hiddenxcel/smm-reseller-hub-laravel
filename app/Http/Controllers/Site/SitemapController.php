<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use Illuminate\Http\Response;

/**
 * The sitemap, generated rather than kept by hand.
 *
 * A hand-written one goes stale the first time a post is published and nobody
 * remembers to edit it — which is the moment it matters most, because a new
 * post is what needs finding.
 *
 * Only public pages. Anything behind a login has nothing to offer a crawler
 * and listing it advertises the shape of the admin console.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $pages = [
            ['loc' => route('home'), 'priority' => '1.0', 'freq' => 'weekly'],
            ['loc' => route('features'), 'priority' => '0.9', 'freq' => 'monthly'],
            ['loc' => route('try'), 'priority' => '0.8', 'freq' => 'monthly'],
            ['loc' => route('what-we-do'), 'priority' => '0.8', 'freq' => 'monthly'],
            ['loc' => route('pricing'), 'priority' => '0.9', 'freq' => 'monthly'],
            ['loc' => route('api-docs'), 'priority' => '0.7', 'freq' => 'monthly'],
            ['loc' => route('blog'), 'priority' => '0.7', 'freq' => 'weekly'],
            ['loc' => route('contact'), 'priority' => '0.5', 'freq' => 'yearly'],
        ];

        $posts = BlogPost::published()
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (BlogPost $post) => [
                'loc' => route('blog.show', $post->slug),
                'lastmod' => $post->updated_at?->toAtomString(),
                'priority' => '0.6',
                'freq' => 'yearly',
            ])
            ->all();

        $xml = view('sitemap', [
            'entries' => [...$pages, ...$posts],
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
