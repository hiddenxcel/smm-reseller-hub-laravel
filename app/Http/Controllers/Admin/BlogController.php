<?php

namespace App\Http\Controllers\Admin;

use App\Models\BlogPost;
use App\Services\Admin\BlogPostActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Posts on the public blog.
 *
 * Permissioned with `tenants.edit` rather than a permission of its own: the
 * console's model is a small set of broad rights, and adding a `blog.*` pair
 * for one screen would mean every existing admin silently losing access to it
 * until someone remembered to grant it.
 */
class BlogController extends AdminController
{
    public function index(): Response
    {
        $this->authorise('tenants.view');

        return Inertia::render('Admin/Blog/Index', [
            'posts' => BlogPost::orderByDesc('id')
                ->get()
                ->map(fn (BlogPost $post) => BlogPostActions::toRow($post))
                ->all(),
            'canManage' => $this->can('tenants.edit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorise('tenants.edit');

        BlogPostActions::create($this->validated($request));

        return back()->with('success', 'Saved as a draft.');
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $this->authorise('tenants.edit');

        BlogPostActions::for($post)->update($this->validated($request));

        return back()->with('success', 'Post updated.');
    }

    public function act(BlogPost $post, string $action): RedirectResponse
    {
        $this->authorise('tenants.edit');

        $actions = BlogPostActions::for($post);

        return match ($action) {
            'publish' => $this->publish($actions, $post),
            'unpublish' => $this->unpublish($actions),
            'delete' => $this->destroy($actions),
        };
    }

    private function publish(BlogPostActions $actions, BlogPost $post): RedirectResponse
    {
        $actions->publish();

        return back()->with('success', "\"{$post->title}\" is live at /blog/{$post->slug}.");
    }

    private function unpublish(BlogPostActions $actions): RedirectResponse
    {
        $actions->unpublish();

        return back()->with('success', 'Taken down. It is a draft again.');
    }

    private function destroy(BlogPostActions $actions): RedirectResponse
    {
        $actions->delete();

        return back()->with('success', 'Post deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            // Capped at the column width. Longer would be truncated by the
            // database, which turns a writing mistake into a data one.
            'excerpt' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:60000'],
            'author' => ['nullable', 'string', 'max:120'],
            // A future date schedules it; the published scope compares against
            // now, so nothing appears before its time.
            'published_at' => ['nullable', 'date'],
        ]);
    }
}
