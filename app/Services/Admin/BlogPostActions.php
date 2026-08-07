<?php

namespace App\Services\Admin;

use App\Models\BlogPost;
use Illuminate\Support\Str;

/**
 * Writing, scheduling and taking down blog posts.
 *
 * Publishing is separated from writing for the same reason as announcements:
 * a draft can be edited freely, but publishing puts the text on a public URL
 * that search engines and other people's links will remember.
 */
class BlogPostActions
{
    public function __construct(private BlogPost $post) {}

    public static function for(BlogPost $post): self
    {
        return new self($post);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes): BlogPost
    {
        $post = BlogPost::create([
            ...$attributes,
            'slug' => self::uniqueSlug($attributes['title']),
        ]);

        AdminAudit::record('blog.create', [
            'post_id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
        ]);

        return $post;
    }

    /**
     * The slug is deliberately not recomputed from a changed title: it is the
     * address, and every link already shared points at the old one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): void
    {
        $this->post->update($attributes);

        AdminAudit::record('blog.update', [
            'post_id' => $this->post->id,
            'title' => $this->post->title,
            'slug' => $this->post->slug,
        ]);
    }

    /** Put it on the public blog, now. */
    public function publish(): void
    {
        $this->post->update(['published_at' => now()]);

        AdminAudit::record('blog.publish', [
            'post_id' => $this->post->id,
            'title' => $this->post->title,
            'slug' => $this->post->slug,
        ]);
    }

    /**
     * Take it down without deleting it.
     *
     * Back to a draft rather than archived: an admin unpublishing a post has
     * usually spotted something wrong in it, and the next step is editing.
     */
    public function unpublish(): void
    {
        $this->post->update(['published_at' => null]);

        AdminAudit::record('blog.unpublish', [
            'post_id' => $this->post->id,
            'title' => $this->post->title,
        ]);
    }

    public function delete(): void
    {
        $id = $this->post->id;
        $title = $this->post->title;
        $slug = $this->post->slug;

        $this->post->delete();

        AdminAudit::record('blog.delete', [
            'post_id' => $id,
            'title' => $title,
            'slug' => $slug,
        ]);
    }

    /**
     * A slug nothing else holds.
     *
     * Two posts with the same title are ordinary — "October update" twice a
     * year — and the second must not fail to save because of it.
     */
    private static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        $suffix = 2;

        while (BlogPost::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toRow(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'author' => $post->author,
            'state' => self::state($post),
            'publishedAt' => $post->published_at?->toIso8601String(),
            'createdAt' => $post->created_at?->toIso8601String(),
        ];
    }

    private static function state(BlogPost $post): string
    {
        if ($post->published_at === null) {
            return 'draft';
        }

        return $post->published_at->isFuture() ? 'scheduled' : 'published';
    }
}
