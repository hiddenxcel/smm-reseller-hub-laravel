<?php

namespace App\Http\Controllers\Admin;

use App\Models\Announcement;
use App\Services\Admin\AnnouncementActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notices shown to every reseller on their dashboard.
 *
 * Publishing is separated from writing because it is the consequential step:
 * a draft can be edited freely, but publishing puts the text in front of the
 * whole platform at once.
 */
class AnnouncementsController extends AdminController
{
    public function index(): Response
    {
        $this->authorise('tenants.view');

        return Inertia::render('Admin/Announcements/Index', [
            'announcements' => Announcement::with('author')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Announcement $a) => AnnouncementActions::toRow($a))
                ->all(),
            'canManage' => $this->can('tenants.edit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorise('tenants.edit');

        AnnouncementActions::create($this->validated($request));

        return back()->with('success', 'Announcement saved as a draft.');
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->authorise('tenants.edit');

        AnnouncementActions::for($announcement)->update($this->validated($request));

        return back()->with('success', 'Announcement updated.');
    }

    public function act(Announcement $announcement, string $action): RedirectResponse
    {
        $this->authorise('tenants.edit');

        $actions = AnnouncementActions::for($announcement);

        return match ($action) {
            'publish' => $this->publish($actions, $announcement),
            'unpublish' => $this->unpublish($actions),
            'delete' => $this->destroy($actions),
        };
    }

    private function publish(AnnouncementActions $actions, Announcement $announcement): RedirectResponse
    {
        $actions->publish();

        return back()->with(
            'success',
            "\"{$announcement->title}\" is now showing on every reseller's dashboard.",
        );
    }

    private function unpublish(AnnouncementActions $actions): RedirectResponse
    {
        $actions->unpublish();

        return back()->with('success', 'Taken down. It is a draft again.');
    }

    private function destroy(AnnouncementActions $actions): RedirectResponse
    {
        $actions->delete();

        return back()->with('success', 'Announcement deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:4000'],
            'level' => ['required', Rule::in(Announcement::LEVELS)],
            'dismissible' => ['required', 'boolean'],
            // A future date schedules it; the scope compares against now, so
            // nothing appears before its time.
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ]);
    }
}
