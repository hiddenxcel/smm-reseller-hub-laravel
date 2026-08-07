<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Notices shown to every reseller.
 *
 * The line this has to hold: nothing reaches a reseller until it is published,
 * and a scheduled notice does not appear before its time. Writing is safe;
 * publishing is the step that puts text in front of the whole platform.
 */
class AdminAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->owner()->create();
        $this->actingAs($this->admin, 'superadmin');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Maintenance on Sunday',
            'body' => 'The panel sync pauses from 02:00 to 04:00 UTC.',
            'level' => 'warning',
            'dismissible' => true,
            'published_at' => null,
            'expires_at' => null,
            ...$overrides,
        ];
    }

    public function test_a_new_announcement_starts_as_a_draft(): void
    {
        $this->post('/hx-control/announcements', $this->payload())->assertRedirect();

        $announcement = Announcement::first();

        $this->assertNotNull($announcement);
        $this->assertSame('draft', $announcement->state());
        $this->assertNull($announcement->published_at);
        $this->assertDatabaseHas('activity_log', ['action' => 'announcements.create']);
    }

    public function test_a_draft_is_not_shown_to_resellers(): void
    {
        $this->post('/hx-control/announcements', $this->payload());

        $tenant = Tenant::factory()->create();
        Auth::guard('superadmin')->logout();
        $this->actingAs($tenant, 'tenant');

        $this->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->has('announcements', 0),
        );
    }

    public function test_publishing_shows_it_to_resellers(): void
    {
        $this->post('/hx-control/announcements', $this->payload());

        $announcement = Announcement::first();

        $this->post("/hx-control/announcements/{$announcement->id}/publish")
            ->assertRedirect();

        $this->assertSame('live', $announcement->fresh()->state());
        $this->assertDatabaseHas('activity_log', ['action' => 'announcements.publish']);

        $tenant = Tenant::factory()->create();
        Auth::guard('superadmin')->logout();
        $this->actingAs($tenant, 'tenant');

        $this->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('announcements', 1)
                ->where('announcements.0.title', 'Maintenance on Sunday')
                ->where('announcements.0.level', 'warning'),
        );
    }

    public function test_a_scheduled_announcement_does_not_appear_early(): void
    {
        Announcement::create([
            ...$this->payload(['published_at' => now()->addDays(2)]),
            'superadmin_id' => $this->admin->id,
        ]);

        $tenant = Tenant::factory()->create();
        Auth::guard('superadmin')->logout();
        $this->actingAs($tenant, 'tenant');

        // Written on Monday to appear on Friday must not appear on Monday.
        $this->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->has('announcements', 0),
        );
    }

    public function test_an_expired_announcement_disappears_on_its_own(): void
    {
        Announcement::create([
            ...$this->payload([
                'published_at' => now()->subDays(3),
                'expires_at' => now()->subDay(),
            ]),
            'superadmin_id' => $this->admin->id,
        ]);

        $tenant = Tenant::factory()->create();
        Auth::guard('superadmin')->logout();
        $this->actingAs($tenant, 'tenant');

        $this->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->has('announcements', 0),
        );
    }

    public function test_unpublishing_takes_it_down_without_deleting_it(): void
    {
        $announcement = Announcement::create([
            ...$this->payload(['published_at' => now()->subHour()]),
            'superadmin_id' => $this->admin->id,
        ]);

        $this->post("/hx-control/announcements/{$announcement->id}/unpublish")
            ->assertRedirect();

        $fresh = $announcement->fresh();
        $this->assertSame('draft', $fresh->state());
        // Back to a draft, not gone: unpublishing usually precedes editing.
        $this->assertDatabaseHas('announcements', ['id' => $announcement->id]);
    }

    public function test_an_announcement_can_be_edited_and_deleted(): void
    {
        $this->post('/hx-control/announcements', $this->payload());
        $announcement = Announcement::first();

        $this->patch("/hx-control/announcements/{$announcement->id}", $this->payload([
            'title' => 'Maintenance moved to Monday',
        ]))->assertRedirect();

        $this->assertSame('Maintenance moved to Monday', $announcement->fresh()->title);

        $this->post("/hx-control/announcements/{$announcement->id}/delete")
            ->assertRedirect();

        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseHas('activity_log', ['action' => 'announcements.delete']);
    }

    public function test_expiry_must_come_after_publication(): void
    {
        $this->post('/hx-control/announcements', $this->payload([
            'published_at' => now()->addDay()->toDateTimeString(),
            'expires_at' => now()->toDateTimeString(),
        ]))->assertSessionHasErrors('expires_at');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_the_level_is_restricted(): void
    {
        $this->post('/hx-control/announcements', $this->payload(['level' => 'apocalyptic']))
            ->assertSessionHasErrors('level');
    }

    public function test_only_the_three_most_recent_live_notices_are_sent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Announcement::create([
                ...$this->payload(['title' => "Notice {$i}"]),
                'published_at' => now()->subMinutes(10 - $i),
                'superadmin_id' => $this->admin->id,
            ]);
        }

        $tenant = Tenant::factory()->create();
        Auth::guard('superadmin')->logout();
        $this->actingAs($tenant, 'tenant');

        // A dashboard buried under banners is a dashboard nobody reads.
        $this->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->has('announcements', 3),
        );
    }

    public function test_a_reseller_cannot_write_announcements(): void
    {
        Auth::guard('superadmin')->logout();

        $this->actingAs(Tenant::factory()->create(), 'tenant');

        $this->get('/hx-control/announcements')->assertRedirect(route('admin.login'));
        $this->post('/hx-control/announcements', $this->payload())
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseCount('announcements', 0);
    }
}
