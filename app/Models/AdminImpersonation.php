<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin's read-only visit to one reseller's account, from start to finish.
 *
 * Not tenant-scoped: it is written and read from the admin console, which runs
 * outside a tenant session, and during an impersonation the tenant session that
 * does exist belongs to the account being viewed — scoping to it would hide the
 * record from the only screen that shows it.
 */
class AdminImpersonation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'superadmin_id',
        'tenant_id',
        'reason',
        'ip',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function superadmin(): BelongsTo
    {
        return $this->belongsTo(Superadmin::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
