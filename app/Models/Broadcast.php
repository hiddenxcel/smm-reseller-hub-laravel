<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One announcement to a reseller's customers.
 */
class Broadcast extends Model
{
    use BelongsToTenant;

    public const SENDING = 'sending';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const PAUSED = 'paused';

    protected $fillable = [
        'tenant_id',
        'message',
        'audience',
        'status',
        'note',
        'total',
        'sent',
        'failed',
        'skipped',
        'finished_at',
    ];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }
}
