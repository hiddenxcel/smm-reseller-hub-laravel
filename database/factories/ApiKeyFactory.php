<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\BotCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * The plaintext of the last key this factory made.
     *
     * A test needs the plaintext to authenticate with, and the model cannot
     * carry it — only the hash is stored. Tests that need both use
     * ApiKey::issue() instead; this is for the many that just need a key to
     * exist.
     */
    public static ?string $lastPlaintext = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plaintext = 'hxk_'.Str::random(48);
        self::$lastPlaintext = $plaintext;

        return [
            'tenant_id' => fn (array $attributes) => BotCustomer::find($attributes['customer_id'])?->tenant_id,
            'customer_id' => BotCustomer::factory(),
            'key_hash' => ApiKey::hash($plaintext),
            'key_prefix' => mb_substr($plaintext, 0, 12),
            'label' => 'Test key',
            'status' => ApiKey::ACTIVE,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => ApiKey::REVOKED]);
    }

    /** @param  list<string>  $ips */
    public function allowingOnly(array $ips): static
    {
        return $this->state(fn () => ['ip_allowlist' => $ips]);
    }

    public function limitedTo(int $perMinute): static
    {
        return $this->state(fn () => ['rate_limit' => $perMinute]);
    }
}
