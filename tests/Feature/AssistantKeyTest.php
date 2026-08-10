<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PlatformSecret;
use App\Models\Superadmin;
use App\Models\Tenant;
use App\Services\Assistant\AssistantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Setting the assistant's DeepSeek key from the console.
 *
 * It lives in the database because editing .env needs SSH, which in practice
 * meant the key was never set and the widget never appeared. That trade is
 * only acceptable while the protections hold, so they are what this tests:
 * encrypted at rest, never sent to the browser, owner-only, and always beaten
 * by .env.
 */
class AssistantKeyTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // No .env key by default, so each test says which side it is testing.
        config(['services.platform_deepseek_key' => null]);
        PlatformSecret::forget();

        $this->owner = Superadmin::factory()->owner()->create();
    }

    public function test_an_owner_can_switch_the_assistant_on_without_a_deploy(): void
    {
        $this->assertFalse(AssistantKey::isReady());

        $this->actingAs($this->owner, 'superadmin')
            ->post('/hx-control/assistant/key', [
                'key' => 'sk-live-abcdefgh1234',
                'enabled' => true,
            ])
            ->assertRedirect();

        PlatformSecret::forget();

        $this->assertTrue(AssistantKey::isReady());
        $this->assertSame('sk-live-abcdefgh1234', AssistantKey::get());
    }

    public function test_the_key_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->owner, 'superadmin')
            ->post('/hx-control/assistant/key', [
                'key' => 'sk-live-abcdefgh1234',
                'enabled' => true,
            ]);

        $stored = DB::table('platform_secrets')->value('value_enc');

        // A database dump on its own has to be worthless: APP_KEY is not in
        // the database, which is the whole argument for storing it here.
        $this->assertNotSame('sk-live-abcdefgh1234', $stored);
        $this->assertStringNotContainsString('abcdefgh1234', (string) $stored);
    }

    public function test_the_console_never_sends_the_key_to_the_browser(): void
    {
        PlatformSecret::create([
            'key' => 'platform_deepseek_key',
            'value_enc' => 'sk-live-abcdefgh1234',
            'enabled' => true,
        ]);

        $response = $this->actingAs($this->owner, 'superadmin')->get('/hx-control/assistant');

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Enough to tell two keys apart while rotating one; useless to
                // anyone who screenshots the page.
                ->where('apiKey.hint', '••••1234')
                ->where('apiKey.source', 'database')
                ->where('enabled', true),
            );

        $response->assertDontSee('sk-live-abcdefgh1234');
    }

    public function test_an_env_key_beats_anything_saved_in_the_console(): void
    {
        config(['services.platform_deepseek_key' => 'sk-from-env-9999']);

        PlatformSecret::create([
            'key' => 'platform_deepseek_key',
            'value_enc' => 'sk-from-console-1111',
            'enabled' => true,
        ]);

        PlatformSecret::forget();

        // An operator who keeps keys on disk — the original arrangement, and
        // the safer one — is never overridden by something typed in a browser.
        $this->assertSame('sk-from-env-9999', AssistantKey::get());
        $this->assertSame('env', AssistantKey::source());
    }

    public function test_a_stored_key_that_is_switched_off_is_not_used(): void
    {
        PlatformSecret::create([
            'key' => 'platform_deepseek_key',
            'value_enc' => 'sk-live-abcdefgh1234',
            'enabled' => false,
        ]);

        PlatformSecret::forget();

        // Storing before trusting is what makes it safe to paste a key in and
        // check it before the public pages start using it.
        $this->assertFalse(AssistantKey::isReady());
        $this->assertNull(AssistantKey::get());
        $this->assertSame('stored-disabled', AssistantKey::source());
    }

    public function test_saving_without_a_key_keeps_the_stored_one(): void
    {
        PlatformSecret::create([
            'key' => 'platform_deepseek_key',
            'value_enc' => 'sk-live-abcdefgh1234',
            'enabled' => true,
        ]);

        // Switching the widget off and on again must not mean pasting the key
        // a second time — nobody has it to hand.
        $this->actingAs($this->owner, 'superadmin')
            ->post('/hx-control/assistant/key', ['key' => '', 'enabled' => false])
            ->assertRedirect();

        PlatformSecret::forget();

        $this->assertFalse(AssistantKey::isReady());
        $this->assertSame('sk-live-abcdefgh1234', PlatformSecret::firstOrFail()->value_enc);
    }

    public function test_the_widget_only_renders_once_a_key_is_in_place(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assistantEnabled', false));

        PlatformSecret::create([
            'key' => 'platform_deepseek_key',
            'value_enc' => 'sk-live-abcdefgh1234',
            'enabled' => true,
        ]);

        PlatformSecret::forget();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assistantEnabled', true));
    }

    public function test_the_assistant_answers_with_the_key_from_the_console(): void
    {
        PlatformSecret::create([
            'key' => 'platform_deepseek_key',
            'value_enc' => 'sk-from-console-1111',
            'enabled' => true,
        ]);

        PlatformSecret::forget();

        Http::fake(['api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Yes, the wizard checks it.']]],
        ])]);

        $this->postJson(route('assistant.ask'), [
            'message' => 'Does the wizard check my webhook before go-live?',
        ])->assertOk()->assertJsonPath('answered_by', 'ai');

        Http::assertSent(
            fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-from-console-1111'),
        );
    }

    public function test_only_an_owner_can_change_the_key(): void
    {
        $support = Superadmin::factory()->create(['role' => 'support']);

        $this->actingAs($support, 'superadmin')
            ->post('/hx-control/assistant/key', ['key' => 'sk-sneaky', 'enabled' => true])
            ->assertForbidden();

        $this->post('/hx-control/logout');

        $this->actingAs(Tenant::factory()->create(), 'tenant')
            ->post('/hx-control/assistant/key', ['key' => 'sk-sneaky', 'enabled' => true])
            ->assertRedirect();

        $this->assertDatabaseCount('platform_secrets', 0);
    }

    public function test_the_change_is_audited_without_recording_the_key(): void
    {
        $this->actingAs($this->owner, 'superadmin')
            ->post('/hx-control/assistant/key', [
                'key' => 'sk-live-abcdefgh1234',
                'enabled' => true,
            ]);

        $entry = ActivityLog::where('action', 'assistant.key.update')->first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->details['key_changed']);

        // An audit log readable by support staff would undo the point of
        // encrypting the value.
        $this->assertStringNotContainsString('abcdefgh1234', json_encode($entry->details));
    }
}
