<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An API credential belonging to one of a reseller's customers.
 *
 * The plaintext exists for exactly one moment — the response to the request
 * that created it. After that only the hash is here, so "show me the key
 * again" has no answer and the screen says so rather than pretending.
 */
class ApiKey extends Model
{
    use BelongsToTenant, HasFactory;

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    /**
     * Requests per minute when a key does not name its own limit.
     *
     * Generous on purpose: the caller is a shop's checkout, and a limit that
     * bites during a normal sales burst is a limit that loses the reseller
     * money. It is here to stop a runaway loop, not to ration.
     */
    public const DEFAULT_RATE_LIMIT = 120;

    /** Prefix on every key, so one is recognisable in a log or a paste. */
    private const PREFIX = 'hxk_';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'key_hash',
        'key_prefix',
        'label',
        'status',
        'rate_limit',
        'ip_allowlist',
        'last_used_at',
        'last_used_ip',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'ip_allowlist' => 'array',
            'rate_limit' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Mint a key, returning the model and the plaintext together.
     *
     * The plaintext is returned rather than stored on the model so it cannot
     * be persisted by accident on a later save.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(BotCustomer $customer, ?string $label = null): array
    {
        $plaintext = self::PREFIX.Str::random(48);

        $key = self::create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'key_hash' => self::hash($plaintext),
            'key_prefix' => mb_substr($plaintext, 0, 12),
            'label' => $label,
            'status' => self::ACTIVE,
        ]);

        return [$key, $plaintext];
    }

    /**
     * How a presented key is turned into something to look up.
     *
     * Plain sha256, not bcrypt: authentication here is a lookup by hash
     * across every key on the platform, which a salted hash cannot do. What
     * bcrypt defends against is a guessable secret, and the secret here is 48
     * random characters — so the slow hash would buy nothing and cost a
     * full-table scan on every request.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Is this address allowed to use the key?
     *
     * A null allowlist means the reseller never restricted it. An empty list
     * is treated the same rather than as "nobody": it can only arise from a
     * key edited down to nothing, and locking someone out of their own
     * integration over an empty array is a worse failure than not enforcing
     * a restriction they did not finish setting.
     */
    public function allowsIp(?string $ip): bool
    {
        if (blank($this->ip_allowlist)) {
            return true;
        }

        return $ip !== null && in_array($ip, $this->ip_allowlist, true);
    }

    public function limitPerMinute(): int
    {
        return $this->rate_limit ?: self::DEFAULT_RATE_LIMIT;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BotCustomer::class, 'customer_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ApiLog::class);
    }
}
