<?php

namespace App\Services\Admin;

use App\Models\Announcement;
use Illuminate\Support\Facades\Auth;

/**
 * Writing, scheduling and taking down platform notices.
 *
 * Publishing is the consequential step — it puts text in front of every
 * reseller at once — so it is audited separately from writing, and a draft can
 * be edited freely until then.
 */
class AnnouncementActions
{
    public function __construct(private Announcement $announcement) {}

    public static function for(Announcement $announcement): self
    {
        return new self($announcement);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes): Announcement
    {
        $announcement = Announcement::create([
            ...$attributes,
            'superadmin_id' => Auth::guard('superadmin')->id(),
        ]);

        AdminAudit::record('announcements.create', [
            'announcement_id' => $announcement->id,
            'title' => $announcement->title,
            'state' => $announcement->state(),
        ]);

        return $announcement;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): void
    {
        $before = $this->announcement->state();

        $this->announcement->update($attributes);

        AdminAudit::record('announcements.update', [
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'from_state' => $before,
            'to_state' => $this->announcement->state(),
        ]);
    }

    /** Show it to everyone, now. */
    public function publish(): void
    {
        $this->announcement->update(['published_at' => now()]);

        AdminAudit::record('announcements.publish', [
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'level' => $this->announcement->level,
        ]);
    }

    /**
     * Take it down without deleting it.
     *
     * Back to a draft rather than expired: an admin unpublishing something has
     * usually spotted a mistake in it, and the next step is editing, not
     * archiving.
     */
    public function unpublish(): void
    {
        $this->announcement->update(['published_at' => null]);

        AdminAudit::record('announcements.unpublish', [
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
        ]);
    }

    public function delete(): void
    {
        $id = $this->announcement->id;
        $title = $this->announcement->title;

        $this->announcement->delete();

        AdminAudit::record('announcements.delete', [
            'announcement_id' => $id,
            'title' => $title,
        ]);
    }

    public static function toRow(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'level' => $announcement->level,
            'dismissible' => (bool) $announcement->dismissible,
            'state' => $announcement->state(),
            'publishedAt' => $announcement->published_at?->toIso8601String(),
            'expiresAt' => $announcement->expires_at?->toIso8601String(),
            'author' => $announcement->author?->displayName(),
            'createdAt' => $announcement->created_at?->toIso8601String(),
        ];
    }
}
