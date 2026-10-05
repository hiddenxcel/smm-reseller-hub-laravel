<?php

namespace Tests\Feature;

use App\Models\TeamMember;
use App\Models\Tenant;
use App\Services\Team\TeamAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Team access is the one feature that lets someone other than the owner into a
 * reseller's account, so the tests are mostly about what they must NOT reach:
 * money, credentials, the account itself, the team — and another reseller's
 * anything.
 */
class TeamTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function member(string $role = 'support', ?Tenant $tenant = null, array $attributes = []): TeamMember
    {
        return TeamMember::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'name' => 'Amina',
            'email' => fake()->unique()->safeEmail(),
            'role' => $role,
            'password_hash' => Hash::make('a-long-password'),
            'accepted_at' => now(),
            ...$attributes,
        ]);
    }

    /** A request as that member: the owner's account with the member's marker on the session. */
    private function asMember(TeamMember $member)
    {
        return $this->actingAs($member->tenant, 'tenant')->withSession(['team_member_id' => $member->id]);
    }

    private function pending(string $role = 'support'): array
    {
        $member = TeamMember::create([
            'tenant_id' => $this->tenant->id,
            'email' => fake()->unique()->safeEmail(),
            'role' => $role,
        ]);

        return [$member, $member->issueInvite()];
    }

    // ---- inviting --------------------------------------------------------

    public function test_the_team_page_needs_a_login(): void
    {
        $this->get(route('team'))->assertRedirect(route('login'));
    }

    public function test_an_invite_stores_only_the_hash_and_shows_the_link_once(): void
    {
        $response = $this->actingAs($this->tenant, 'tenant')->post(route('team.store'), [
            'email' => 'new@example.com',
            'name' => 'Newcomer',
            'role' => 'support',
        ]);

        $response->assertRedirect(route('team'));

        $member = TeamMember::where('email', 'new@example.com')->firstOrFail();
        $invite = session('invite');

        $this->assertSame('new@example.com', $invite['email']);

        $token = basename($invite['url']);

        $this->assertSame(TeamMember::hashToken($token), $member->invite_token_hash);
        $this->assertNotSame($token, $member->invite_token_hash);
        $this->assertTrue($member->isPending());
        $this->assertFalse($member->isActive());
    }

    public function test_invite_validation(): void
    {
        $existing = $this->member();

        // Already a team member, already an owner, a role that does not exist.
        foreach ([
            ['email' => $existing->email, 'role' => 'support'],
            ['email' => $this->tenant->email, 'role' => 'support'],
            ['email' => 'ok@example.com', 'role' => 'owner'],
            ['email' => 'not-an-email', 'role' => 'support'],
        ] as $payload) {
            $this->actingAs($this->tenant, 'tenant')
                ->post(route('team.store'), $payload)
                ->assertSessionHasErrors();
        }

        $this->assertSame(1, TeamMember::count());
    }

    public function test_the_team_has_a_cap(): void
    {
        for ($i = 0; $i < TeamMember::MAX_PER_TENANT; $i++) {
            $this->member();
        }

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('team.store'), ['email' => 'one-more@example.com', 'role' => 'viewer'])
            ->assertSessionHas('error');

        $this->assertNull(TeamMember::where('email', 'one-more@example.com')->first());
    }

    public function test_a_new_link_ends_the_old_one(): void
    {
        [$member, $old] = $this->pending();

        $this->actingAs($this->tenant, 'tenant')->post(route('team.link', $member->id));

        $new = basename(session('invite')['url']);

        $this->assertNull(TeamMember::findByInvite($old));
        $this->assertNotNull(TeamMember::findByInvite($new));
    }

    // ---- accepting -------------------------------------------------------

    public function test_accepting_sets_a_password_signs_in_and_spends_the_link(): void
    {
        [$member, $token] = $this->pending('admin');

        $this->get(route('team.join', $token))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Team/Join')
                ->where('valid', true)
                ->where('email', $member->email)
                ->where('business', $this->tenant->business_name));

        $this->post(route('team.join.store', $token), [
            'name' => 'Amina',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect(route('dashboard'));

        $member->refresh();

        $this->assertTrue($member->isActive());
        $this->assertNull($member->invite_token_hash);
        $this->assertAuthenticatedAs($this->tenant, 'tenant');
        $this->assertSame($member->id, session('team_member_id'));

        // The link is single-use. Signed out first: a signed-in member is turned
        // away from the owner-only team routes, this one included.
        $this->app['auth']->guard('tenant')->logout();
        $this->flushSession();

        $this->get(route('team.join', $token))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('valid', false));
    }

    public function test_an_expired_link_is_refused(): void
    {
        [$member, $token] = $this->pending();

        $member->update(['invite_expires_at' => now()->subMinute()]);

        $this->get(route('team.join', $token))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('valid', false));

        $this->post(route('team.join.store', $token), [
            'name' => 'X',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ]);

        $this->assertFalse($member->fresh()->isActive());
        $this->assertGuest('tenant');
    }

    public function test_a_weak_password_is_refused(): void
    {
        [$member, $token] = $this->pending();

        $this->post(route('team.join.store', $token), [
            'name' => 'X',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertFalse($member->fresh()->isActive());
    }

    // ---- signing in ------------------------------------------------------

    public function test_a_member_signs_in_with_their_own_email_and_password(): void
    {
        $member = $this->member('support');

        $this->post(route('login'), ['email' => $member->email, 'password' => 'a-long-password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->tenant, 'tenant');
        $this->assertSame($member->id, session('team_member_id'));
        $this->assertNotNull($member->fresh()->last_login_at);
    }

    public function test_a_wrong_password_and_a_pending_member_cannot_sign_in(): void
    {
        $member = $this->member();

        $this->post(route('login'), ['email' => $member->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('tenant');

        [$pending] = $this->pending();

        $this->post(route('login'), ['email' => $pending->email, 'password' => 'anything'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('tenant');
    }

    public function test_the_owner_signing_in_clears_a_leftover_member_marker(): void
    {
        $this->withSession(['team_member_id' => 999]);

        $this->post(route('login'), ['email' => $this->tenant->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertNull(session('team_member_id'));
    }

    // ---- what a role can reach ------------------------------------------

    public function test_the_access_matrix(): void
    {
        $cases = [
            // role, route, method, action, expected
            ['viewer', 'analytics', 'GET', null, true],
            ['viewer', 'customers.index', 'GET', null, true],
            ['viewer', 'customers.export', 'GET', null, false],
            ['viewer', 'support-bot.inbox.reply', 'POST', null, false],
            ['viewer', 'services.update', 'PATCH', null, false],
            ['viewer', 'settings', 'GET', null, false],

            ['support', 'dashboard', 'GET', null, true],
            ['support', 'analytics', 'GET', null, false],
            ['support', 'support-bot.inbox.reply', 'POST', null, true],
            ['support', 'support-bot.tickets.update', 'PATCH', null, true],
            ['support', 'support-bot.rules.store', 'POST', null, false],
            ['support', 'customers.index', 'GET', null, true],
            ['support', 'customers.act', 'POST', 'block', false],
            ['support', 'services.index', 'GET', null, false],

            ['admin', 'services.update', 'PATCH', null, true],
            ['admin', 'customers.act', 'POST', 'block', true],
            ['admin', 'customers.act', 'POST', 'wallet', false],
            ['admin', 'support-bot.rules.store', 'POST', null, true],

            // Owner-only, for every role.
            ...collect(['admin', 'support', 'viewer'])->flatMap(fn (string $role) => [
                [$role, 'billing', 'GET', null, false],
                [$role, 'billing.checkout', 'POST', null, false],
                [$role, 'profile.edit', 'GET', null, false],
                [$role, 'profile.destroy', 'DELETE', null, false],
                [$role, 'password.update', 'PUT', null, false],
                [$role, 'team', 'GET', null, false],
                [$role, 'team.store', 'POST', null, false],
                [$role, 'api-access', 'GET', null, false],
                [$role, 'onboarding.payments.store', 'POST', null, false],
                [$role, 'onboarding.whatsapp.store', 'POST', null, false],
                [$role, 'order-bot.gateways.store', 'POST', null, false],
                [$role, 'order-bot.providers.store', 'POST', null, false],
                [$role, 'logout', 'POST', null, true],
                // A route nobody has thought about yet is closed.
                [$role, 'some.future.route', 'GET', null, false],
                [$role, null, 'GET', null, false],
            ])->all(),
        ];

        foreach ($cases as [$role, $route, $method, $action, $expected]) {
            $this->assertSame(
                $expected,
                TeamAccess::allows($role, $route, $method, $action),
                sprintf('%s %s %s%s should be %s', $role, $method, $route ?? '(unnamed)', $action ? " [{$action}]" : '', $expected ? 'allowed' : 'refused'),
            );
        }
    }

    public function test_an_unknown_role_gets_nothing(): void
    {
        $this->assertFalse(TeamAccess::allows('owner', 'dashboard', 'GET'));
        $this->assertFalse(TeamAccess::allows('', 'dashboard', 'GET'));
    }

    public function test_a_viewer_can_look_but_a_write_is_bounced_with_a_message(): void
    {
        $viewer = $this->member('viewer');

        $this->asMember($viewer)->get(route('analytics'))->assertOk();

        $this->asMember($viewer)
            ->from(route('dashboard'))
            ->post(route('support-bot.inbox.reply'), ['phone' => '255700000000', 'message' => 'hi'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }

    public function test_a_member_is_sent_to_the_dashboard_from_a_page_their_role_cannot_open(): void
    {
        foreach (['admin', 'support', 'viewer'] as $role) {
            $member = $this->member($role);

            foreach (['billing', 'profile.edit', 'team', 'api-access'] as $page) {
                $this->asMember($member)->get(route($page))
                    ->assertRedirect(route('dashboard'));
            }
        }
    }

    public function test_a_json_request_gets_a_403_not_a_redirect(): void
    {
        $viewer = $this->member('viewer');

        $this->asMember($viewer)
            ->getJson(route('customers.export'))
            ->assertForbidden();
    }

    public function test_the_owner_is_never_restricted(): void
    {
        foreach (['billing', 'profile.edit', 'team'] as $page) {
            $this->actingAs($this->tenant, 'tenant')->get(route($page))->assertOk();
        }
    }

    // ---- removal and isolation ------------------------------------------

    public function test_removing_a_member_ends_their_session_on_the_next_request(): void
    {
        $member = $this->member('admin');

        $this->asMember($member)->get(route('dashboard'))->assertOk();

        $member->delete();

        $this->asMember($member)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest('tenant');
    }

    public function test_a_marker_for_another_accounts_member_ends_the_session(): void
    {
        $other = Tenant::factory()->create();
        $theirs = $this->member('admin', $other);

        // This tenant's session, carrying a marker that belongs to someone else.
        $this->actingAs($this->tenant, 'tenant')
            ->withSession(['team_member_id' => $theirs->id])
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_one_reseller_cannot_touch_anothers_team(): void
    {
        $other = Tenant::factory()->create();
        $theirs = $this->member('admin', $other);

        $this->actingAs($this->tenant, 'tenant')
            ->patch(route('team.update', $theirs->id), ['role' => 'viewer'])
            ->assertNotFound();

        $this->actingAs($this->tenant, 'tenant')
            ->delete(route('team.destroy', $theirs->id))
            ->assertNotFound();

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('team.link', $theirs->id))
            ->assertNotFound();

        $this->assertSame('admin', $theirs->fresh()->role);
    }

    public function test_the_list_shows_only_this_accounts_members(): void
    {
        $mine = $this->member('support');
        $other = Tenant::factory()->create();
        $this->member('admin', $other);

        $this->actingAs($this->tenant, 'tenant')->get(route('team'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Team/Index')
                ->has('members', 1)
                ->where('members.0.email', $mine->email)
                ->where('members.0.status', 'active'));
    }

    public function test_the_owner_can_change_a_role_and_remove_a_member(): void
    {
        $member = $this->member('viewer');

        $this->actingAs($this->tenant, 'tenant')
            ->patch(route('team.update', $member->id), ['role' => 'admin'])
            ->assertSessionHas('success');

        $this->assertSame('admin', $member->fresh()->role);

        $this->actingAs($this->tenant, 'tenant')->delete(route('team.destroy', $member->id));

        $this->assertNull(TeamMember::find($member->id));
    }

    public function test_the_page_marks_who_is_signed_in_and_what_they_can_open(): void
    {
        $viewer = $this->member('viewer');

        $this->asMember($viewer)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.member.role', 'viewer')
                ->where('auth.member.roleLabel', 'Viewer')
                ->where('auth.member.can', fn ($can) => collect($can)->contains('analytics')
                    && ! collect($can)->contains('billing')
                    && ! collect($can)->contains('team')));

        // A fresh session: the owner, with no member marker on it.
        $this->flushSession();

        $this->actingAs($this->tenant, 'tenant')->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.member', null));
    }
}
