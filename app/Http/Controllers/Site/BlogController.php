<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Services\Assistant\AssistantKey;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    public function index(): Response
    {
        $posts = BlogPost::published()
            ->orderByDesc('published_at')
            ->paginate(9)
            ->through(fn (BlogPost $post) => [
                'slug' => $post->slug,
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'author' => $post->author,
                'published_at' => $post->published_at?->toDateString(),
            ]);

        return Inertia::render('Public/Blog/Index', [
            'posts' => $posts,
            'demoNumber' => config('services.demo_whatsapp_number'),
            'assistantEnabled' => AssistantKey::widgetEnabled(),
        ]);
    }

    public function show(string $slug): Response
    {
        // Scoped to published rather than found-then-checked: a draft should
        // 404 the same way a made-up slug does, so its existence is not
        // leaked by a different response.
        $post = BlogPost::published()->where('slug', $slug)->firstOrFail();

        return Inertia::render('Public/Blog/Show', [
            'post' => [
                'slug' => $post->slug,
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'body' => $post->body,
                'author' => $post->author,
                'published_at' => $post->published_at?->toDateString(),
            ],
            'more' => BlogPost::published()
                ->whereKeyNot($post->id)
                ->orderByDesc('published_at')
                ->limit(3)
                ->get()
                ->map(fn (BlogPost $other) => [
                    'slug' => $other->slug,
                    'title' => $other->title,
                    'excerpt' => $other->excerpt,
                    'published_at' => $other->published_at?->toDateString(),
                ]),
            'demoNumber' => config('services.demo_whatsapp_number'),
            'assistantEnabled' => AssistantKey::widgetEnabled(),
        ]);
    }
}
