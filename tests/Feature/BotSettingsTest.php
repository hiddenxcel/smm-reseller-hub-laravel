<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Bots\BotSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Settings are stored as a partial jsonb blob merged over defaults, which is
 * easy to get subtly wrong — and the failure mode is a reseller's setting
 * vanishing without a word.
 */
class BotSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function save(array $settings): void
    {
        BotSettings::save($this->tenant->id, 'order', $settings);
    }

    /** Named to avoid colliding with the framework's HTTP get(). */
    private function setting(string $key): mixed
    {
        return Arr::get(BotSettings::for($this->tenant->id, 'order'), $key);
    }

    public function test_defaults_apply_when_nothing_is_stored(): void
    {
        $this->assertSame('USD', $this->setting('shop.currency'));
        $this->assertTrue($this->setting('spam.enabled'));
    }

    public function test_a_stored_value_overrides_its_default(): void
    {
        $this->save(['shop' => ['currency' => 'TZS']]);

        $this->assertSame('TZS', $this->setting('shop.currency'));
    }

    public function test_untouched_defaults_survive_a_partial_save(): void
    {
        $this->save(['shop' => ['currency' => 'TZS']]);

        $this->assertSame(1, $this->setting('shop.min_topup'));
        $this->assertTrue($this->setting('spam.enabled'));
    }

    public function test_a_key_with_no_default_is_kept(): void
    {
        // The regression: walking only the defaults dropped anything not
        // listed there, so a newer setting disappeared on the next read.
        $this->save(['shop' => ['bot_tested' => true]]);

        $this->assertTrue($this->setting('shop.bot_tested'));
    }

    public function test_a_whole_section_with_no_default_is_kept(): void
    {
        $this->save(['experimental' => ['something' => 'yes']]);

        $this->assertSame('yes', $this->setting('experimental.something'));
    }

    public function test_list_values_are_replaced_not_merged(): void
    {
        // Merging these would resurrect numbers the reseller deleted.
        $this->save(['staff' => ['numbers' => ['255700000001', '255700000002']]]);
        $this->save(['staff' => ['numbers' => ['255700000001']]]);

        $this->assertSame(['255700000001'], $this->setting('staff.numbers'));
    }

    public function test_settings_are_per_bot(): void
    {
        BotSettings::save($this->tenant->id, 'order', ['shop' => ['currency' => 'TZS']]);

        $support = BotSettings::for($this->tenant->id, 'support');

        $this->assertSame('USD', Arr::get($support, 'shop.currency'));
    }

    public function test_settings_are_per_tenant(): void
    {
        $other = Tenant::factory()->create();

        $this->save(['shop' => ['currency' => 'TZS']]);

        $this->assertSame('USD', Arr::get(BotSettings::for($other->id, 'order'), 'shop.currency'));
    }

    public function test_staff_numbers_are_matched_ignoring_formatting(): void
    {
        $this->save(['staff' => ['numbers' => ['+255 700 000 001']]]);

        $this->assertTrue(BotSettings::isStaff($this->tenant->id, 'order', '255700000001'));
        $this->assertFalse(BotSettings::isStaff($this->tenant->id, 'order', '255700000009'));
    }

    public function test_a_test_number_registered_under_either_bot_counts(): void
    {
        // A reseller who added their number to test the order bot should not
        // have to add it again under support.
        BotSettings::save($this->tenant->id, 'order', [
            'shop' => ['test_numbers' => ['255700000001']],
        ]);

        $this->assertTrue(BotSettings::isTestNumber($this->tenant->id, '255700000001'));
        $this->assertFalse(BotSettings::isTestNumber($this->tenant->id, '255700000002'));
    }
}
